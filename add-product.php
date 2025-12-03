<?php
// add_product_admin.php
// Place this file in the same folder as connect.php
// connect.php must set $conn as a mysqli connection

include "connect.php";
if (!isset($conn) || !$conn) {
    die("Database connection not found. Ensure connect.php defines \$conn (mysqli).");
}

// -------------------- Ensure needed tables exist --------------------
$conn->query("
CREATE TABLE IF NOT EXISTS categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(191) NOT NULL UNIQUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$conn->query("
CREATE TABLE IF NOT EXISTS subcategories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  category_id INT NOT NULL,
  name VARCHAR(191) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cat_sub (category_id, name),
  FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$conn->query("
CREATE TABLE IF NOT EXISTS product (
  id INT AUTO_INCREMENT PRIMARY KEY,
  category_id INT NOT NULL,
  subcategory_id INT DEFAULT NULL,
  model_name VARCHAR(255) NOT NULL,
  model_description VARCHAR(255) DEFAULT NULL,
  model_image VARCHAR(255) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT,
  FOREIGN KEY (subcategory_id) REFERENCES subcategories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// -------------------- Seed categories (only if empty) --------------------
$seed = ['Ups', 'Battery', 'Education products', 'Solar products', 'Servo Stabilizer', 'AVR', 'Inverter', 'Waterpump'];
$res = $conn->query("SELECT COUNT(*) AS c FROM categories");
$c = ($res) ? $res->fetch_assoc()['c'] : 0;
if ((int)$c === 0) {
    $stmt = $conn->prepare("INSERT INTO categories (name) VALUES (?)");
    foreach ($seed as $s) {
        $stmt->bind_param('s', $s);
        @$stmt->execute();
    }
    $stmt->close();
}

// -------------------- Handle POST actions --------------------
$messages = [];

function safe_redirect($url) {
    header("Location: $url");
    exit;
}

// 1) Add a new category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_category') {
    $new_cat = trim($_POST['new_category'] ?? '');
    if ($new_cat === '') {
        $messages[] = "Category name cannot be empty.";
    } else {
        $stmt = $conn->prepare("INSERT IGNORE INTO categories (name) VALUES (?)");
        $stmt->bind_param('s', $new_cat);
        if ($stmt->execute()) {
            $messages[] = "Category added (or already existed): " . htmlspecialchars($new_cat);
        } else {
            $messages[] = "Error adding category: " . $conn->error;
        }
        $stmt->close();
    }
    // redirect to prevent re-submit
    safe_redirect($_SERVER['PHP_SELF']);
}

// 2) Add a new subcategory
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_subcategory') {
    $cat_id = (int)($_POST['category_for_subcat'] ?? 0);
    $new_sub = trim($_POST['new_subcategory'] ?? '');
    if ($cat_id <= 0 || $new_sub === '') {
        $messages[] = "Select a category and provide a subcategory name.";
    } else {
        $stmt = $conn->prepare("INSERT IGNORE INTO subcategories (category_id, name) VALUES (?, ?)");
        $stmt->bind_param('is', $cat_id, $new_sub);
        if ($stmt->execute()) {
            $messages[] = "Subcategory added (or already existed): " . htmlspecialchars($new_sub);
        } else {
            $messages[] = "Error adding subcategory: " . $conn->error;
        }
        $stmt->close();
    }
    safe_redirect($_SERVER['PHP_SELF']);
}

// 3) Add a product (file upload)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_product') {
    $cat_id = (int)($_POST['category'] ?? 0);
    $subcat_id = (int)($_POST['sub_category'] ?? 0) ?: null;
    $model_name = trim($_POST['model_name'] ?? '');

    if ($cat_id <= 0 || $model_name === '') {
        $messages[] = "Category and Model Name are required.";
    } else {
        // Prepare uploads directory
        $upload_base = __DIR__ . '/uploads/products/';
        if (!is_dir($upload_base)) {
            mkdir($upload_base, 0755, true);
        }

        // Function to handle one file
        $saveFile = function($fileFieldName) use ($upload_base, &$messages) {
            if (!isset($_FILES[$fileFieldName]) || $_FILES[$fileFieldName]['error'] === UPLOAD_ERR_NO_FILE) return null;
            $file = $_FILES[$fileFieldName];

            if ($file['error'] !== UPLOAD_ERR_OK) {
                $messages[] = "Upload error for {$fileFieldName}.";
                return null;
            }

            // Basic MIME check
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            $allowed = [
                'image/jpeg' => 'jpg',
                'image/png'  => 'png',
                'image/gif'  => 'gif',
                'image/webp' => 'webp',
                'application/pdf' => 'pdf'
            ];

            if (!isset($allowed[$mime])) {
                $messages[] = "File type not allowed for {$fileFieldName}: " . htmlspecialchars($mime);
                return null;
            }

            $ext = $allowed[$mime];
            $base = bin2hex(random_bytes(8));
            $filename = $base . '.' . $ext;
            $dest = $upload_base . $filename;

            if (!move_uploaded_file($file['tmp_name'], $dest)) {
                $messages[] = "Failed to move uploaded file for {$fileFieldName}.";
                return null;
            }

            // Return relative path to store in DB
            return 'uploads/products/' . $filename;
        };

        $model_description_path = $saveFile('model_description'); // could be PDF or image
        $model_image_path = $saveFile('model_image'); // image

        // Insert to DB
        $stmt = $conn->prepare("INSERT INTO product (category_id, subcategory_id, model_name, model_description, model_image) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param('iisss', $cat_id, $subcat_id, $model_name, $model_description_path, $model_image_path);

        if ($stmt->execute()) {
            $messages[] = "Product added successfully.";
        } else {
            $messages[] = "Insert error: " . $conn->error;
        }
        $stmt->close();
    }
    safe_redirect($_SERVER['PHP_SELF']);
}

// -------------------- Fetch categories & subcategories for UI --------------------
$categories = [];
$catRes = $conn->query("SELECT id, name FROM categories ORDER BY name");
while ($r = $catRes->fetch_assoc()) $categories[] = $r;

// Build a grouped subcategories array for JS (so we can change subcategory dropdown without reload)
$subcats_grouped = [];
$subRes = $conn->query("SELECT id, category_id, name FROM subcategories ORDER BY name");
while ($r = $subRes->fetch_assoc()) {
    $subcats_grouped[(int)$r['category_id']][] = $r;
}

// Optional: Fetch products to display (last 20)
$products = [];
$prodRes = $conn->query("SELECT p.*, c.name AS category_name, s.name AS subcategory_name
                         FROM product p
                         JOIN categories c ON p.category_id = c.id
                         LEFT JOIN subcategories s ON p.subcategory_id = s.id
                         ORDER BY p.created_at DESC LIMIT 50");
while ($r = $prodRes->fetch_assoc()) $products[] = $r;

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Admin: Add Product</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background:#f7f9fb; }
    .card { margin-top:20px; }
    .logo { height:44px; }
    .upload-note { font-size:0.9rem; color:#666; }
    .thumb { max-width:80px; max-height:60px; object-fit:cover; border-radius:4px; border:1px solid #ddd; }
  </style>
</head>
<body>
<nav class="navbar navbar-light bg-white shadow-sm">
  <div class="container">
    <a class="navbar-brand d-flex align-items-center" href="#">
      <img src="data:image/svg+xml;charset=UTF-8,%3Csvg width='40' height='40' xmlns='http://www.w3.org/2000/svg'%3E%3Crect rx='8' width='40' height='40' fill='%23007bff'/%3E%3Ctext x='50%25' y='55%25' dominant-baseline='middle' text-anchor='middle' font-size='20' fill='white'%3EC%3C/text%3E%3C/svg%3E" class="logo" alt="Logo">
      <span class="ml-2 font-weight-bold">Company</span>
    </a>
    <div>
      <a href="admin.php" class="btn btn-primary">Admin</a>
    </div>
  </div>
</nav>

<div class="container">
  <div class="row">
    <div class="col-md-7">
      <div class="card">
        <div class="card-body">
          <h4 class="card-title">Add Product</h4>

          <?php if (!empty($messages)): ?>
            <?php foreach ($messages as $m): ?>
              <div class="alert alert-info py-1"><?= htmlspecialchars($m) ?></div>
            <?php endforeach; ?>
          <?php endif; ?>

          <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="add_product">

            <div class="form-group">
              <label>Category</label>
              <select id="categorySelect" name="category" class="form-control" required>
                <option value="">-- choose category --</option>
                <?php foreach ($categories as $c): ?>
                  <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="form-group">
              <label>Subcategory (optional)</label>
              <select id="subcatSelect" name="sub_category" class="form-control">
                <option value="">-- choose subcategory --</option>
                <!-- options populated by JS -->
              </select>
              <small class="upload-note">If there is no subcategory you can add one below.</small>
            </div>

            <div class="form-group">
              <label>Model Name</label>
              <input name="model_name" class="form-control" required>
            </div>

            <div class="form-group">
              <label>Model Description file (pdf/image)</label>
              <input type="file" name="model_description" accept=".pdf,image/*" class="form-control-file">
            </div>

            <div class="form-group">
              <label>Model Image (jpg/png/webp)</label>
              <input type="file" name="model_image" accept="image/*" class="form-control-file">
            </div>

            <button class="btn btn-success" type="submit">Upload Product</button>
          </form>
        </div>
      </div>

      <!-- Add new category & subcategory -->
      <div class="card">
        <div class="card-body">
          <h5 class="card-title">Add Category</h5>
          <form method="post" class="form-inline">
            <input type="hidden" name="action" value="add_category">
            <div class="form-group mr-2">
              <input name="new_category" class="form-control" placeholder="New category name" required>
            </div>
            <button class="btn btn-primary" type="submit">Add Category</button>
          </form>

          <hr>

          <h5 class="card-title">Add Subcategory (select category)</h5>
          <form method="post" class="form-inline mt-2">
            <input type="hidden" name="action" value="add_subcategory">
            <div class="form-group mr-2">
              <select name="category_for_subcat" class="form-control" required>
                <option value="">-- choose category --</option>
                <?php foreach ($categories as $c): ?>
                  <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group mr-2">
              <input name="new_subcategory" class="form-control" placeholder="New subcategory name" required>
            </div>
            <button class="btn btn-primary" type="submit">Add Subcategory</button>
          </form>

        </div>
      </div>

    </div>

    <div class="col-md-5">
      <div class="card">
        <div class="card-body">
          <h5 class="card-title">Recent Products</h5>
          <div class="list-group">
            <?php if (empty($products)): ?>
              <div class="text-muted">No products yet.</div>
            <?php endif; ?>
            <?php foreach ($products as $p): ?>
              <div class="list-group-item d-flex align-items-center">
                <div class="mr-3">
                  <?php if ($p['model_image']): ?>
                    <img src="<?= htmlspecialchars($p['model_image']) ?>" class="thumb" alt="">
                  <?php else: ?>
                    <div class="thumb" style="display:inline-block;background:#eee;"></div>
                  <?php endif; ?>
                </div>
                <div>
                  <strong><?= htmlspecialchars($p['model_name']) ?></strong><br>
                  <small class="text-muted"><?= htmlspecialchars($p['category_name']) ?><?= $p['subcategory_name'] ? " / " . htmlspecialchars($p['subcategory_name']) : '' ?></small>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<script>
  // Subcategories grouped by category (generated from server)
  const subcats = <?= json_encode($subcats_grouped, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>;

  function populateSubcategoriesFor(catId) {
    const sel = document.getElementById('subcatSelect');
    sel.innerHTML = '<option value="">-- choose subcategory --</option>';
    if (!catId || !subcats[catId]) return;
    subcats[catId].forEach(function(s) {
      const o = document.createElement('option');
      o.value = s.id;
      o.text = s.name;
      sel.appendChild(o);
    });
  }

  document.getElementById('categorySelect').addEventListener('change', function() {
    populateSubcategoriesFor(this.value);
  });

  // On load, if categories exist, populate subcats for first item
  (function(){
    const catSel = document.getElementById('categorySelect');
    if (catSel.value) populateSubcategoriesFor(catSel.value);
  })();
</script>

</body>
</html>
