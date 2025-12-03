<?php
// admin_products.php
session_start();

// Require login — redirect to login.php if not authenticated
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

include __DIR__ . '/connect.php';
if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection missing. Make sure connect.php defines \$conn (mysqli).");
}

// Helper: escape output
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }

// ------------------ Logout handler ------------------
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_unset();
    session_destroy();
    header('Location: login.php');
    exit;
}

// ------------------ Handle Delete (GET) ------------------
$messages = [];
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $delId = (int)$_GET['delete'];

    // Get image path to delete file
    $stmt = $conn->prepare("SELECT model_image FROM product WHERE id = ?");
    $stmt->bind_param('i', $delId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();

    if ($row) {
        // Attempt to delete DB row
        $stmt = $conn->prepare("DELETE FROM product WHERE id = ?");
        $stmt->bind_param('i', $delId);
        if ($stmt->execute()) {
            $messages[] = "Product deleted.";

            // remove image file if it exists and is inside uploads/
            if (!empty($row['model_image'])) {
                $uploadsDir = __DIR__ . '/uploads';
                $imgFs = $uploadsDir . '/' . basename($row['model_image']);
                if (file_exists($imgFs) && strpos(realpath($imgFs), realpath($uploadsDir)) === 0) {
                    @unlink($imgFs);
                }
            }
        } else {
            $messages[] = "Delete failed: " . $conn->error;
        }
        $stmt->close();
    } else {
        $messages[] = "Product not found.";
    }

    // Redirect to remove GET param and avoid double-delete on refresh
    header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
    exit;
}

// ------------------ Fetch categories and subcategories ------------------
// Categories
$categories = [];
$catRes = $conn->query("SELECT id, name FROM categories ORDER BY name");
while ($r = $catRes->fetch_assoc()) $categories[] = $r;

// Subcategories
$subcategories = [];
$subRes = $conn->query("SELECT id, category_id, name FROM subcategories ORDER BY name");
while ($r = $subRes->fetch_assoc()) $subcategories[] = $r;

// ------------------ Determine filter selection (GET) ------------------
$filter_cat = isset($_GET['category']) && is_numeric($_GET['category']) ? (int)$_GET['category'] : 0;
$filter_sub = isset($_GET['subcategory']) && is_numeric($_GET['subcategory']) ? (int)$_GET['subcategory'] : 0;

// ------------------ Fetch products (with joins) ------------------
$products = [];
$sql = "SELECT p.*, c.name AS category_name, s.name AS subcategory_name
        FROM product p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN subcategories s ON p.subcategory_id = s.id";
$params = [];
$types = '';
$clauses = [];

if ($filter_cat > 0) {
    $clauses[] = "p.category_id = ?";
    $types .= 'i';
    $params[] = $filter_cat;
}
if ($filter_sub > 0) {
    $clauses[] = "p.subcategory_id = ?";
    $types .= 'i';
    $params[] = $filter_sub;
}
if ($clauses) {
    $sql .= " WHERE " . implode(' AND ', $clauses);
}
$sql .= " ORDER BY p.created_at DESC LIMIT 200";

$stmt = $conn->prepare($sql);
if ($stmt) {
    if ($types) {
        $bindNames = array_merge([$types], $params);
        $tmp = [];
        foreach ($bindNames as $k => $v) $tmp[$k] = &$bindNames[$k];
        call_user_func_array([$stmt, 'bind_param'], $tmp);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $products[] = $r;
    $stmt->close();
} else {
    $products = [];
    $messages[] = "Query error: " . $conn->error;
}

// ------------------ Helper: group subcategories by category id for JS ------------------
$subcats_by_cat = [];
foreach ($subcategories as $s) {
    $subcats_by_cat[(int)$s['category_id']][] = $s;
}

// ------------------ Helper: compute base path for links ------------------
$scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\'); // e.g. /products
$basePath = $scriptDir === '/' ? '' : $scriptDir; // avoid double slashes
$uploadsWebPath = $basePath . '/uploads'; // used in <img src>
$uploadsFsPath = __DIR__ . '/uploads';    // used for file_exists
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Product Management - Admin</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background:#f7f9fb; }
    .logo { height:44px; }
    .thumb { width:70px; height:50px; object-fit:cover; border-radius:6px; border:1px solid #e6e6e6; }
    .table thead th { vertical-align: middle; }
    .card { margin-top:20px; }
    .topbar { padding:18px 0; }
    footer { padding:18px 0; color:#6c757d; }
    .desc-cell { max-width:320px; word-wrap:break-word; white-space:normal; }
  </style>
</head>
<body>
<nav class="navbar navbar-light bg-white shadow-sm">
  <div class="container">
    <a class="navbar-brand d-flex align-items-center" href="#">
      <!-- simple placeholder svg logo -->
      <img src="data:image/svg+xml;charset=UTF-8,%3Csvg width='40' height='40' xmlns='http://www.w3.org/2000/svg'%3E%3Crect rx='8' width='40' height='40' fill='%23007bff'/%3E%3Ctext x='50%25' y='55%25' dominant-baseline='middle' text-anchor='middle' font-size='20' fill='white'%3EC%3C/text%3E%3C/svg%3E" class="logo" alt="Logo">
      <span class="ml-2 font-weight-bold">Company</span>
    </a>

    <div class="d-flex align-items-center">
      <a class="btn btn-primary mr-2" href="<?= h($basePath . '/add-product.php') ?>">Add Product</a>
      <a class="btn btn-sm btn-danger" href="?action=logout">Logout</a>
    </div>
  </div>
</nav>

<div class="container">
  <div class="topbar d-flex align-items-center">
    <div>
      <h1 class="display-5 mb-0">Product Management</h1>
      <p class="text-muted small mb-0">Add, update & remove products</p>
    </div>
  </div>

  <?php foreach ($messages as $m): ?>
    <div class="alert alert-info mt-3"><?= h($m) ?></div>
  <?php endforeach; ?>

  <div class="card">
    <div class="card-body">
      <form method="get" class="form-inline mb-3">
        <label class="mr-2">Category</label>
        <select id="filterCategory" name="category" class="form-control mr-3">
          <option value="0">-- All Categories --</option>
          <?php foreach ($categories as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= $filter_cat === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?></option>
          <?php endforeach; ?>
        </select>

        <label class="mr-2">Subcategory</label>
        <select id="filterSubcategory" name="subcategory" class="form-control mr-3">
          <option value="0">-- All Subcategories --</option>
          <!-- options populated by JS on load -->
        </select>

        <button class="btn btn-outline-primary ml-auto" type="submit">Apply</button>
      </form>

      <div class="table-responsive">
        <table class="table table-hover">
          <thead class="thead-light">
            <tr>
              <th>Image</th>
              <th>Model</th>
              <th>Description</th>
              <th>Category/Subcategory</th>
              <th style="width:160px">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($products)): ?>
              <tr><td colspan="5" class="text-muted">No products found.</td></tr>
            <?php else: ?>
              <?php foreach ($products as $p): ?>
                <tr>
                  <td> <?php if (!empty($p['model_image']) && file_exists(__DIR__ . '/' . $p['model_image'])): ?> 
                        <img src="<?= h($p['model_image']) ?>" alt="" class="thumb"> 
                        <?php else: ?> <div class="thumb" style="background:#f0f0f0;"></div> <?php endif; ?> 
                  </td>

                  <td style="vertical-align:middle;"><?= h($p['model_name']) ?></td>

                  <td> <?php if (!empty($p['model_description']) && file_exists(__DIR__ . '/' . $p['model_description'])): ?> 
                    <img src="<?= h($p['model_description']) ?>" alt="" class="thumb"> 
                    <?php else: ?> <div class="thumb" style="background:#f0f0f0;"></div> <?php endif; ?> 
                  </td>

                  <td style="vertical-align:middle">
                    <?= h($p['category_name']) ?><?= !empty($p['subcategory_name']) ? ' / ' . h($p['subcategory_name']) : '' ?>
                  </td>

                  <td style="vertical-align:middle">
                    <a class="btn btn-sm btn-outline-primary" href="<?= h($basePath . '/edit-product.php?id=' . (int)$p['id']) ?>">Edit</a>
                    <a class="btn btn-sm btn-outline-danger" href="?delete=<?= (int)$p['id'] ?>" onclick="return confirm('Delete this product?')">Delete</a>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

    </div>
  </div>

  <footer class="text-center mt-4">
    <small>Admin • product manager</small>
  </footer>
</div>

<script>
  // subcategories grouped by category id (from PHP)
  const subcatsByCat = <?= json_encode($subcats_by_cat, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>;
  const filterCat = <?= (int)$filter_cat ?>;
  const filterSub = <?= (int)$filter_sub ?>;

  function populateSubcats(catId, preselect) {
    const sel = document.getElementById('filterSubcategory');
    sel.innerHTML = '<option value="0">-- All Subcategories --</option>';
    if (!catId || !subcatsByCat[catId]) return;
    subcatsByCat[catId].forEach(function(s){
      const opt = document.createElement('option');
      opt.value = s.id;
      opt.text = s.name;
      if (preselect && preselect === s.id) opt.selected = true;
      sel.appendChild(opt);
    });
  }

  document.getElementById('filterCategory').addEventListener('change', function(){
    populateSubcats(parseInt(this.value,10), 0);
  });

  // On load, populate appropriate subcategories and preselect
  (function(){
    if (filterCat && subcatsByCat[filterCat]) populateSubcats(filterCat, filterSub || 0);
    else {
      document.getElementById('filterSubcategory').innerHTML = '<option value="0">-- All Subcategories --</option>';
    }
  })();
</script>
</body>
</html>
