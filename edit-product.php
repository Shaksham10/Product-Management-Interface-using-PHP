<?php
// edit_product.php
// Usage: edit_product.php?id=123
include __DIR__ . '/connect.php';
if (!isset($conn) || !$conn) die("DB connection missing.");

function h($s){ return htmlspecialchars($s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }

// --- get product id ---
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    die("Invalid product id.");
}

// --- fetch categories & subcategories ---
$categories = [];
$catRes = $conn->query("SELECT id, name FROM categories ORDER BY name");
while ($r = $catRes->fetch_assoc()) $categories[] = $r;

$subcategories = [];
$subRes = $conn->query("SELECT id, category_id, name FROM subcategories ORDER BY name");
while ($r = $subRes->fetch_assoc()) $subcategories[] = $r;

// make grouped subcats for JS
$subcats_by_cat = [];
foreach ($subcategories as $s) $subcats_by_cat[(int)$s['category_id']][] = $s;

// --- fetch product ---
$stmt = $conn->prepare("SELECT * FROM product WHERE id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$prodRes = $stmt->get_result();
$product = $prodRes->fetch_assoc();
$stmt->close();

if (!$product) die("Product not found.");

// --- handle POST update ---
$messages = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    $cat_id = isset($_POST['category']) ? (int)$_POST['category'] : 0;
    $subcat_id = isset($_POST['sub_category']) && $_POST['sub_category'] !== '' ? (int)$_POST['sub_category'] : null;
    $model_name = trim($_POST['model_name'] ?? '');

    if ($cat_id <= 0) {
        $messages[] = "Please choose a category.";
    } elseif ($model_name === '') {
        $messages[] = "Model name required.";
    } else {
        // ensure upload dir exists
        $upload_base = __DIR__ . '/uploads/products/';
        if (!is_dir($upload_base)) mkdir($upload_base, 0755, true);

        // helper to save file; returns relative path or null
        $saveFile = function($fieldName, $allowedMap, &$errors) use ($upload_base) {
            if (!isset($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] === UPLOAD_ERR_NO_FILE) return null;
            $f = $_FILES[$fieldName];
            if ($f['error'] !== UPLOAD_ERR_OK) { $errors[] = "Upload error for $fieldName."; return null; }
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $f['tmp_name']);
            finfo_close($finfo);
            if (!isset($allowedMap[$mime])) { $errors[] = "File type not allowed for $fieldName: $mime"; return null; }
            $ext = $allowedMap[$mime];
            $base = bin2hex(random_bytes(8));
            $filename = $base . '.' . $ext;
            $dest = $upload_base . $filename;
            if (!move_uploaded_file($f['tmp_name'], $dest)) { $errors[] = "Failed to move uploaded file for $fieldName."; return null; }
            return 'uploads/products/' . $filename;
        };

        // allowed file types
        $imgAllowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
        $descAllowed = ['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];

        $new_image_path = $saveFile('model_image', $imgAllowed, $messages);
        $new_desc_path  = $saveFile('model_description', $descAllowed, $messages);

        // If file uploads produced errors, don't update DB
        if (empty($messages)) {
            // fetch existing paths to delete if replaced
            $old_image = $product['model_image'];
            $old_desc  = $product['model_description'];

            // prepare update statement (allow null for subcat)
            $stmt = $conn->prepare("UPDATE product SET category_id = ?, subcategory_id = ?, model_name = ?, model_description = ?, model_image = ? WHERE id = ?");
            // convert null to null and bind types accordingly (iisssi)
            // We'll set description/image to new path if uploaded, otherwise keep old path
            $desc_for_db = $new_desc_path ?? $old_desc;
            $img_for_db  = $new_image_path ?? $old_image;
            // bind params (iisssi) where subcategory may be null -> treat as int or null via bind_param requires value; pass null as null
            // mysqli bind_param doesn't accept null directly via type 'i' -> use explicit cast and then set to null with s and later using null? Simpler: use dynamic SQL that uses ? for subcat and allow null by binding as integer or null using mysqli_stmt::bind_param (works with null).
            if ($subcat_id === null) {
                // bind as NULL using 'i' with null variable
                $nullSub = null;
                $stmt->bind_param('iissi', $cat_id, $nullSub, $model_name, $desc_for_db, $img_for_db);
                // But this binding doesn't match param count; simpler approach: use two-step SQL to avoid bind issues.
                $stmt->close();
                $stmt = $conn->prepare("UPDATE product SET category_id = ?, subcategory_id = NULL, model_name = ?, model_description = ?, model_image = ? WHERE id = ?");
                $stmt->bind_param('isssi', $cat_id, $model_name, $desc_for_db, $img_for_db, $id);
            } else {
                $stmt->bind_param('iisssi', $cat_id, $subcat_id, $model_name, $desc_for_db, $img_for_db, $id);
            }

            $ok = $stmt->execute();
            $stmt->close();

            if ($ok) {
                // delete old files if replaced
                if ($new_image_path && $old_image && strpos(realpath(__DIR__ . '/' . $old_image), realpath(__DIR__ . '/uploads')) === 0) {
                    @unlink(__DIR__ . '/' . $old_image);
                }
                if ($new_desc_path && $old_desc && strpos(realpath(__DIR__ . '/' . $old_desc), realpath(__DIR__ . '/uploads')) === 0) {
                    @unlink(__DIR__ . '/' . $old_desc);
                }

                // redirect back to listing
                header("Location: admin.php?msg=" . urlencode("Product updated."));
                exit;
            } else {
                $messages[] = "DB update failed: " . $conn->error;
            }
        }
    }

    // if errors, reload $product from DB to show current values + messages
    $stmt = $conn->prepare("SELECT * FROM product WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $prd = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($prd) $product = $prd;
}

// --- helper for preselecting subcategory list in HTML ---
function option_selected($a, $b){ return ($a == $b) ? 'selected' : ''; }

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
          <h4 class="card-title">Update Product</h4>


  <?php foreach ($messages as $m): ?>
    <div class="alert alert-danger"><?= h($m) ?></div>
  <?php endforeach; ?>

  <div class="card">
    <div class="card-body">
      <form method="post" enctype="multipart/form-data">
        <div class="form-row">
          <div class="form-group col-md-6">
            <label>Category</label>
            <select id="category" name="category" class="form-control" required>
              <option value="">-- choose category --</option>
              <?php foreach ($categories as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= option_selected($c['id'], $product['category_id']) ?>><?= h($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group col-md-6">
            <label>Subcategory (optional)</label>
            <select id="sub_category" name="sub_category" class="form-control">
              <option value="">-- choose subcategory --</option>
              <!-- JS populates -->
            </select>
          </div>
        </div>

        <div class="form-group">
          <label>Model Name</label>
          <input type="text" name="model_name" class="form-control" value="<?= h($product['model_name']) ?>" required>
        </div>

        <div class="form-row">
          <div class="form-group col-md-6">
            <label>Model Image (replace)</label>
            <?php if (!empty($product['model_image']) && file_exists(__DIR__ . '/' . $product['model_image'])): ?>
              <div class="mb-2">
                <img src="<?= h($product['model_image']) ?>" class="thumb" alt="Current image">
              </div>
                <div><small class="text-muted">Uploading a new file will replace the current image.</small></div>
            <?php else: ?>
              <div class="mb-2"><small class="text-muted">No image currently.</small></div>
            <?php endif; ?>
            <input type="file" name="model_image" accept="image/*" class="form-control-file">
          </div>

          <div class="form-group col-md-6">
            <label>Model Description file (pdf/images)</label>
            <?php if (!empty($product['model_description']) && file_exists(__DIR__ . '/' . $product['model_description'])): ?>
              <div class="mb-2">
                <a href="<?= h($product['model_description']) ?>" target="_blank">Download current file</a>
              </div>
              <div><small class="text-muted">Uploading a new file will replace the current description file.</small></div>
            <?php else: ?>
              <div class="mb-2"><small class="text-muted">No description file currently.</small></div>
            <?php endif; ?>
            <input type="file" name="model_description" accept=".pdf,image/*" class="form-control-file">
          </div>
        </div>

        <div class="mt-3">
          <button type = "submit" name="save" class="btn btn-primary">Save changes</button>
          <a href="admin.php" class="btn btn-secondary ml-2">Cancel</a>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
  // subcategories grouped by category id from PHP
  const subcatsByCat = <?= json_encode($subcats_by_cat, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>;
  const currentCat = <?= (int)$product['category_id'] ?>;
  const currentSub = <?= (int)$product['subcategory_id'] ?>;

  function populateSubcats(catId, preselect) {
    const sel = document.getElementById('sub_category');
    sel.innerHTML = '<option value="">-- choose subcategory --</option>';
    if (!catId || !subcatsByCat[catId]) return;
    subcatsByCat[catId].forEach(function(s){
      const o = document.createElement('option');
      o.value = s.id;
      o.text = s.name;
      if (preselect && preselect == s.id) o.selected = true;
      sel.appendChild(o);
    });
  }

  const catEl = document.getElementById('category');
  catEl.addEventListener('change', function(){
    populateSubcats(this.value, 0);
  });

  // on load populate subcats for current category
  (function(){
    if (currentCat) populateSubcats(currentCat, currentSub);
    else document.getElementById('sub_category').innerHTML = '<option value="">-- choose subcategory --</option>';
  })();
</script>
</body>
</html>
