<?php
date_default_timezone_set('Asia/Manila');

const PRODUCTS_FILE = __DIR__ . '/data/products.xml';
const SALES_FILE    = __DIR__ . '/data/sales.xml';
const SIZES         = ['medium' => 'Medium', 'large' => 'Large', 'extraLarge' => 'Extra Large'];
const ICONS         = ['Milk Tea' => 'fa-mug-hot', 'Frappe' => 'fa-glass-whiskey', 'Fruit Teas' => 'fa-apple-alt', 'Hot Coffee' => 'fa-coffee', 'Iced Coffee' => 'fa-glass-water'];

/* ---------- helpers ---------- */

function h(mixed $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function peso(float $n): string { return '₱' . number_format($n, 2); }
function post(string $key): string { $v = $_POST[$key] ?? ''; return is_string($v) ? trim($v) : ''; }
function js(mixed $v): string { return json_encode($v, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); }

function loadXml(string $file, string $root): SimpleXMLElement
{
    if (!is_file($file)) {
        file_put_contents($file, '<?xml version="1.0" encoding="UTF-8"?><' . $root . '/>');
    }
    $xml = simplexml_load_file($file);
    if ($xml === false) {
        http_response_code(500);
        exit('Could not read ' . basename($file) . '. Check that it is valid XML.');
    }
    return $xml;
}

function saveXml(SimpleXMLElement $xml, string $file): bool
{
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = true;
    $dom->loadXML($xml->asXML());
    return file_put_contents($file, $dom->saveXML(), LOCK_EX) !== false;
}

function addText(SimpleXMLElement $parent, string $name, string $value): void
{
    $parent->addChild($name, htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8'));
}

/* ---------- record a new sale (POST) ---------- */

$products = loadXml(PRODUCTS_FILE, 'products');
$error = '';
$added = isset($_GET['added']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $product = null;
    foreach ($products->product as $p) {
        if ((string)$p['id'] === post('product')) { $product = $p; break; }
    }
    $size = post('size');
    $qty  = filter_var(post('qty'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
    $cogs = filter_var(post('cogs'), FILTER_VALIDATE_FLOAT);
    $date = post('date');
    $d    = DateTime::createFromFormat('Y-m-d', $date);

    if (!$product || !isset(SIZES[$size]) || $qty === false || $cogs === false || $cogs < 0 || !$d || $d->format('Y-m-d') !== $date) {
        $error = 'Check the sale details: choose a product and size, enter a quantity of 1 or more, a cost of 0 or more, and a valid date.';
    } else {
        $lock = fopen(__DIR__ . '/data/.lock', 'c');
        flock($lock, LOCK_EX);
        $salesXml = loadXml(SALES_FILE, 'sales');
        $nextId = 700;
        foreach ($salesXml->sale as $s) { $nextId = max($nextId, (int)$s['id']); }
        $sale = $salesXml->addChild('sale');
        $sale->addAttribute('id', (string)($nextId + 1));
        addText($sale, 'product',  (string)$product->name);
        addText($sale, 'category', (string)$product['category']);
        addText($sale, 'size',     $size);
        addText($sale, 'qty',      (string)$qty);
        addText($sale, 'price',    (string)(float)$product->{$size});
        addText($sale, 'cogs',     (string)$cogs);
        addText($sale, 'date',     $date);
        $saved = saveXml($salesXml, SALES_FILE);
        flock($lock, LOCK_UN);
        fclose($lock);
        if ($saved) { header('Location: index.php?added=1#sales'); exit; }
        $error = 'The sale could not be saved. Make sure the data folder is writable.';
    }
}

/* ---------- dashboard figures from sales.xml ---------- */

$sales = loadXml(SALES_FILE, 'sales');
$rows = []; $revenue = 0.0; $totalCogs = 0.0; $byProduct = []; $byCategory = [];

foreach ($sales->sale as $s) {
    $qty = (int)$s->qty; $price = (float)$s->price; $unit = (float)$s->cogs;
    $name = (string)$s->product; $cat = (string)$s->category;
    $rev = $qty * $price; $cost = $qty * $unit;
    $revenue += $rev; $totalCogs += $cost;
    $byProduct[$name]['qty'] = ($byProduct[$name]['qty'] ?? 0) + $qty;
    $byProduct[$name]['rev'] = ($byProduct[$name]['rev'] ?? 0) + $rev;
    $byCategory[$cat] = ($byCategory[$cat] ?? 0) + $rev;
    $rows[] = [
        'id' => (int)$s['id'], 'product' => $name, 'size' => SIZES[(string)$s->size] ?? (string)$s->size,
        'qty' => $qty, 'price' => $price, 'cogs' => $unit, 'date' => (string)$s->date,
        'rev' => $rev, 'profit' => $rev - $cost,
    ];
}
$netProfit = $revenue - $totalCogs;

usort($rows, fn($a, $b) => [$b['date'], $b['id']] <=> [$a['date'], $a['id']]);
uasort($byProduct, fn($a, $b) => $b['qty'] <=> $a['qty']);
$topSellers = array_slice($byProduct, 0, 4, true);

$chartYear = $rows ? (int)substr($rows[0]['date'], 0, 4) : (int)date('Y');
$monthly = array_fill(0, 12, 0.0);
foreach ($rows as $r) {
    $m = (int)substr($r['date'], 5, 2);
    if ((int)substr($r['date'], 0, 4) === $chartYear && $m >= 1 && $m <= 12) { $monthly[$m - 1] += $r['rev']; }
}

/* ---------- product groups for tabs and the sale form ---------- */

$grouped = [];
foreach ($products->product as $p) { $grouped[(string)$p['category']][] = $p; }
$firstCategory = (string)array_key_first($grouped);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mr. Boy Coffee Shop</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3"></script>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>

<aside class="sidebar">
    <div class="logo"><h1>Mr. Boy</h1><span>COFFEE SHOP</span></div>
    <ul class="nav">
        <li><a href="#dashboard" data-page="dashboard"><i class="fas fa-chart-line"></i><span>Dashboard</span></a></li>
        <li><a href="#inventory" data-page="inventory"><i class="fas fa-boxes-stacked"></i><span>Inventory</span></a></li>
        <li><a href="#sales" data-page="sales"><i class="fas fa-receipt"></i><span>Sales</span></a></li>
    </ul>
</aside>

<main class="main-content">

    <!-- DASHBOARD -->
    <section class="page" id="page-dashboard">
        <div class="page-header">
            <h2>Dashboard</h2>
            <p>Sales and profit analysis</p>
        </div>

        <div class="kpi-grid">
            <div class="kpi-card"><div class="icon"><i class="fas fa-peso-sign"></i></div><h4>Total Revenue</h4><h2><?= peso($revenue) ?></h2></div>
            <div class="kpi-card"><div class="icon"><i class="fas fa-money-bill-wave"></i></div><h4>Total COGS</h4><h2><?= peso($totalCogs) ?></h2></div>
            <div class="kpi-card"><div class="icon"><i class="fas fa-chart-simple"></i></div><h4>Net Profit</h4><h2><?= peso($netProfit) ?></h2></div>
        </div>

        <div class="chart-grid">
            <div class="card"><h3>Revenue Trend (<?= $chartYear ?>)</h3><div class="chart-container"><canvas id="revenueChart"></canvas></div></div>
            <div class="card"><h3>Category Sales</h3><div class="chart-container"><canvas id="categoryChart"></canvas></div></div>
        </div>

        <div class="card">
            <h3>Top Sellers</h3>
            <?php if ($topSellers): ?>
            <div class="seller-list">
                <?php foreach ($topSellers as $name => $t): ?>
                <div class="seller-item">
                    <h4><?= h($name) ?></h4>
                    <p><?= $t['qty'] ?> unit<?= $t['qty'] === 1 ? '' : 's' ?> sold</p>
                    <div class="seller-price"><?= peso($t['rev']) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <p class="empty">No sales yet. Record a sale on the Sales page.</p>
            <?php endif; ?>
        </div>
    </section>

    <!-- INVENTORY -->
    <section class="page card" id="page-inventory" hidden>
        <h3><i class="fas fa-mug-hot"></i> <span id="inv-name"><?= h($firstCategory) ?></span> Inventory</h3>

        <div class="inventory-tabs">
            <?php foreach (array_keys($grouped) as $cat): ?>
            <button type="button" class="inv-tab<?= $cat === $firstCategory ? ' active' : '' ?>" data-category="<?= h($cat) ?>">
                <i class="fas <?= h(ICONS[$cat] ?? 'fa-mug-hot') ?>"></i><?= h($cat) ?>
            </button>
            <?php endforeach; ?>
        </div>

        <div class="table-container">
            <table>
                <thead><tr><th>ID</th><th>Product Name</th><th>Medium</th><th>Large</th><th>Extra Large</th></tr></thead>
                <tbody id="inventory-body">
                    <?php foreach ($grouped as $cat => $items): foreach ($items as $p): ?>
                    <tr data-category="<?= h($cat) ?>"<?= $cat === $firstCategory ? '' : ' hidden' ?>>
                        <td><?= h($p['id']) ?></td>
                        <td><?= h($p->name) ?></td>
                        <td class="price">₱<?= h((float)$p->medium) ?></td>
                        <td class="price">₱<?= h((float)$p->large) ?></td>
                        <td class="price">₱<?= h((float)$p->extraLarge) ?></td>
                    </tr>
                    <?php endforeach; endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- SALES -->
    <section class="page card" id="page-sales" hidden>
        <h3>Sales Transactions</h3>

        <?php if ($added): ?><div class="notice ok" role="status">Sale recorded.</div><?php endif; ?>
        <?php if ($error): ?><div class="notice err" role="alert"><?= h($error) ?></div><?php endif; ?>

        <form class="sale-form" method="post" action="index.php#sales">
            <label>Product
                <select name="product" required>
                    <option value="">Select a product</option>
                    <?php foreach ($grouped as $cat => $items): ?>
                    <optgroup label="<?= h($cat) ?>">
                        <?php foreach ($items as $p): ?><option value="<?= h($p['id']) ?>"><?= h($p->name) ?></option><?php endforeach; ?>
                    </optgroup>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Size
                <select name="size" required>
                    <?php foreach (SIZES as $key => $label): ?><option value="<?= h($key) ?>"><?= h($label) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label>Quantity <input type="number" name="qty" min="1" max="1000" value="1" required></label>
            <label>Cost per unit (₱) <input type="number" name="cogs" min="0" step="0.01" required></label>
            <label>Date <input type="date" name="date" value="<?= date('Y-m-d') ?>" required></label>
            <button type="submit" class="btn">Record sale</button>
        </form>

        <?php if ($rows): ?>
        <div class="table-container">
            <table>
                <thead><tr><th>Transaction ID</th><th>Product</th><th>Size</th><th>Quantity</th><th>Price</th><th>COGS</th><th>Date</th><th>Revenue</th><th>Profit</th></tr></thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= $r['id'] ?></td>
                        <td><?= h($r['product']) ?></td>
                        <td><?= h($r['size']) ?></td>
                        <td><?= $r['qty'] ?></td>
                        <td><?= peso($r['price']) ?></td>
                        <td><?= peso($r['cogs']) ?></td>
                        <td><?= h($r['date']) ?></td>
                        <td><?= peso($r['rev']) ?></td>
                        <td class="profit"><?= peso($r['profit']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <p class="empty">No sales recorded yet. Fill in the form above to add the first one.</p>
        <?php endif; ?>
    </section>

</main>

<script>
    /* Page navigation */
    function show(name) {
        if (!document.getElementById('page-' + name)) name = 'dashboard';
        document.querySelectorAll('.page').forEach(p => p.hidden = p.id !== 'page-' + name);
        document.querySelectorAll('.nav a').forEach(a => a.classList.toggle('active', a.dataset.page === name));
    }
    window.addEventListener('hashchange', () => show(location.hash.slice(1)));
    show(location.hash.slice(1));

    /* Inventory category tabs */
    document.querySelectorAll('.inv-tab').forEach(tab => tab.addEventListener('click', () => {
        const cat = tab.dataset.category;
        document.querySelectorAll('.inv-tab').forEach(t => t.classList.toggle('active', t === tab));
        document.getElementById('inv-name').textContent = cat;
        document.querySelectorAll('#inventory-body tr').forEach(r => r.hidden = r.dataset.category !== cat);
    }));

    /* Charts (data comes from sales.xml through PHP) */
    if (window.Chart) {
        new Chart(document.getElementById('revenueChart'), {
            type: 'line',
            data: {
                labels: ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'],
                datasets: [{ label: 'Revenue (₱)', data: <?= js($monthly) ?>, borderColor: '#e8751a',
                    backgroundColor: 'rgba(232,117,26,0.10)', borderWidth: 2, fill: true, tension: 0.4, pointRadius: 3 }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
        });
        new Chart(document.getElementById('categoryChart'), {
            type: 'doughnut',
            data: {
                labels: <?= js(array_keys($byCategory)) ?>,
                datasets: [{ data: <?= js(array_values($byCategory)) ?>, borderWidth: 0,
                    backgroundColor: ['#c6530b', '#e8751a', '#ff9f43', '#ffb866', '#ffd3a3'] }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
        });
    }
</script>
</body>
