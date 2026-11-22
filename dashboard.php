<?php
require_once 'includes/functions.php';
requireLogin();
require_once 'includes/db.php';

$userId = (int)$_SESSION['user_id'];
$categories = ['Тамақ','Көлік','Сауда','Денсаулық','Ойын-сауық','Жалақы','Басқа'];

// GET параметрлерін алу және тексеру
$search = trim($_GET['search'] ?? '');
$category = trim($_GET['category'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
$sort = $_GET['sort'] ?? 'date_desc';
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
$page = ($page && $page > 0) ? $page : 1;
$perPage = 5;

if (!in_array($category, $categories, true)) $category = '';
foreach (['date_from' => &$dateFrom, 'date_to' => &$dateTo] as $key => &$dateValue) {
    if ($dateValue !== '') {
        $dateObject = DateTime::createFromFormat('Y-m-d', $dateValue);
        if (!$dateObject || $dateObject->format('Y-m-d') !== $dateValue) $dateValue = '';
    }
}
unset($dateValue);
$allowedSorts = [
    'date_desc' => 'transaction_date DESC, id DESC',
    'date_asc' => 'transaction_date ASC, id ASC',
    'amount_desc' => 'amount DESC, id DESC',
    'amount_asc' => 'amount ASC, id ASC',
    'name_asc' => 'description ASC, id DESC'
];
if (!isset($allowedSorts[$sort])) $sort = 'date_desc';

// Dashboard қорытындысы барлық операция бойынша есептеледі (сүзгіге тәуелсіз)
$allStmt = $pdo->prepare('SELECT type, amount, wallet FROM transactions WHERE user_id = :uid');
$allStmt->execute([':uid' => $userId]);
$allTransactions = $allStmt->fetchAll();
$income = 0; $expense = 0;
$wallets = ['Kaspi' => 125000, 'Halyk' => 85000, 'Қолма-қол' => 15000];
foreach ($allTransactions as $tx) {
    $amount = (float)$tx['amount'];
    if ($tx['type'] === 'income') $income += $amount; else $expense += $amount;
    if (isset($wallets[$tx['wallet']])) $wallets[$tx['wallet']] += $tx['type'] === 'income' ? $amount : -$amount;
}
$balance = array_sum($wallets);

// Іздеу және сүзгі шарттары — барлығы серверде SQL арқылы орындалады
$where = ['user_id = :uid'];
$params = [':uid' => $userId];
if ($search !== '') {
    $where[] = '(description LIKE :search_description OR category LIKE :search_category OR wallet LIKE :search_wallet)';
    $searchValue = '%' . $search . '%';
    $params[':search_description'] = $searchValue;
    $params[':search_category'] = $searchValue;
    $params[':search_wallet'] = $searchValue;
}
if ($category !== '') {
    $where[] = 'category = :category';
    $params[':category'] = $category;
}
if ($dateFrom !== '') {
    $where[] = 'transaction_date >= :date_from';
    $params[':date_from'] = $dateFrom;
}
if ($dateTo !== '') {
    $where[] = 'transaction_date <= :date_to';
    $params[':date_to'] = $dateTo;
}
$whereSql = implode(' AND ', $where);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE $whereSql");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

$sql = "SELECT id, type, amount, category, wallet, description, transaction_date
        FROM transactions WHERE $whereSql
        ORDER BY {$allowedSorts[$sort]} LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) $stmt->bindValue($key, $value);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$transactions = $stmt->fetchAll();

$queryParams = $_GET;
unset($queryParams['page']);
$paginationBase = http_build_query($queryParams);
$notice = $_SESSION['qarjy_notice'] ?? '';
unset($_SESSION['qarjy_notice']);
$pageTitle = 'Қаржы панелі — Qarjy';
include 'includes/header.php';
?>
<main class="dash">
<small>ҚАРЖЫЛЫҚ ШОЛУ</small>
<h1>Қайырлы күн, <?=e($_SESSION['user_name'] ?? 'Қолданушы')?>! 👋</h1>
<?php if ($notice): ?><div class="success"><?=e($notice)?></div><?php endif; ?>
<div class="stats">
 <article><span>Жалпы баланс</span><h2><?=money($balance)?></h2></article>
 <article><span>Жалпы кіріс</span><h2 class="green"><?=money($income)?></h2></article>
 <article><span>Жалпы шығын</span><h2 class="red"><?=money($expense)?></h2></article>
</div>
<section class="panel">
 <div class="between"><h3>Менің операцияларым</h3><a class="btn" href="add_transaction.php">＋ Операция қосу</a></div>
 <form class="filter-form" method="get" action="dashboard.php">
  <div class="filter-grid">
   <label>Іздеу<input type="search" name="search" value="<?=e($search)?>" placeholder="Атауы, санаты, әмиян..."></label>
   <label>Санат<select name="category"><option value="">Барлық санат</option><?php foreach($categories as $c): ?><option value="<?=e($c)?>" <?=$category===$c?'selected':''?>><?=e($c)?></option><?php endforeach; ?></select></label>
   <label>Бастапқы күн<input type="date" name="date_from" value="<?=e($dateFrom)?>"></label>
   <label>Соңғы күн<input type="date" name="date_to" value="<?=e($dateTo)?>"></label>
   <label>Сұрыптау<select name="sort">
    <option value="date_desc" <?=$sort==='date_desc'?'selected':''?>>Алдымен жаңалары</option>
    <option value="date_asc" <?=$sort==='date_asc'?'selected':''?>>Алдымен ескілері</option>
    <option value="amount_desc" <?=$sort==='amount_desc'?'selected':''?>>Сома: көптен азға</option>
    <option value="amount_asc" <?=$sort==='amount_asc'?'selected':''?>>Сома: аздан көпке</option>
    <option value="name_asc" <?=$sort==='name_asc'?'selected':''?>>Атауы бойынша</option>
   </select></label>
  </div>
  <div class="filter-actions"><button class="btn" type="submit">Іздеу және сүзу</button><a class="reset-link" href="dashboard.php">Тазарту</a></div>
 </form>
 <p class="result-count">Табылған операция саны: <strong><?=$totalRows?></strong> · Бір бетте <?=$perPage?> операция</p>
 <?php if (!$transactions): ?><p>Сұраныс бойынша операция табылмады.</p>
 <?php else: ?>
 <div class="table-wrap"><table class="transactions-table">
 <thead><tr><th>Күні</th><th>Операция</th><th>Санат / әмиян</th><th>Сома</th><th>Әрекет</th></tr></thead><tbody>
 <?php foreach ($transactions as $tx): ?>
 <tr>
  <td><?=e(date('d.m.Y', strtotime($tx['transaction_date'])))?></td>
  <td><?=e($tx['description'] ?: $tx['category'])?><small><?=$tx['type']==='income'?'Кіріс':'Шығын'?></small></td>
  <td><?=e($tx['category'])?><small><?=e($tx['wallet'])?></small></td>
  <td class="<?=$tx['type']==='income'?'green':'red'?>"><?=$tx['type']==='income'?'+':'−'?> <?=money($tx['amount'])?></td>
  <td class="actions"><a class="edit-link" href="edit_transaction.php?id=<?=(int)$tx['id']?>">Өңдеу</a>
   <form method="post" action="delete_transaction.php" onsubmit="return confirm('Осы операцияны жоясыз ба?')">
    <input type="hidden" name="csrf_token" value="<?=e(csrfToken())?>"><input type="hidden" name="id" value="<?=(int)$tx['id']?>">
    <button class="delete-btn" type="submit">Жою</button>
   </form>
  </td>
 </tr>
 <?php endforeach; ?>
 </tbody></table></div>
 <?php endif; ?>
 <?php if ($totalPages > 1): ?>
 <nav class="pagination" aria-label="Беттерге бөлу">
  <?php if ($page > 1): ?><a href="?<?=$paginationBase ? $paginationBase.'&' : ''?>page=<?=$page-1?>">← Алдыңғы</a><?php endif; ?>
  <span>Бет <?=$page?> / <?=$totalPages?></span>
  <?php if ($page < $totalPages): ?><a href="?<?=$paginationBase ? $paginationBase.'&' : ''?>page=<?=$page+1?>">Келесі →</a><?php endif; ?>
 </nav>
 <?php endif; ?>
</section>
<section class="panel"><h3>Менің әмияндарым</h3><div class="wallets">
 <?php foreach ($wallets as $wallet => $amount): ?><div><?=e($wallet)?><strong><?=money($amount)?></strong></div><?php endforeach; ?>
</div></section>
</main>
<?php include 'includes/footer.php'; ?>
