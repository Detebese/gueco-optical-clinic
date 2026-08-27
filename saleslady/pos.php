<?php
define('BASE_URL', '../');
require_once __DIR__ . '/../config/functions.php';
requireRole('saleslady');

$pageTitle  = 'Point of Sale';
$breadcrumb = ['Saleslady', 'POS'];
$db = getDB();

// Process sale submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'process_sale') {
    $patientId  = (int)($_POST['patient_id'] ?? 0) ?: null;
    $items      = json_decode($_POST['items'] ?? '[]', true);
    $payMethod  = $_POST['payment_method'] ?? 'cash';
    $discount   = (float)($_POST['discount'] ?? 0);
    $amountPaid = (float)($_POST['amount_paid'] ?? 0);
    $notes      = sanitize(trim($_POST['notes'] ?? ''));

    if (empty($items)) {
        echo json_encode(['success'=>false,'error'=>'Cart is empty.']);
        exit;
    }

    // Calculate subtotal
    $subtotal = 0;
    foreach ($items as &$item) {
        $prod = $db->prepare("SELECT * FROM products WHERE id=? AND status='active'");
        $prod->execute([$item['id']]); $prod = $prod->fetch();
        if (!$prod) { echo json_encode(['success'=>false,'error'=>'Product not found: '.$item['id']]); exit; }
        if ($prod['stock_quantity'] < $item['qty']) {
            echo json_encode(['success'=>false,'error'=>"Insufficient stock for: {$prod['name']}. Available: {$prod['stock_quantity']}"]); exit;
        }
        $item['price'] = $prod['price'];
        $item['total'] = $prod['price'] * $item['qty'];
        $subtotal += $item['total'];
    }

    $total = max(0, $subtotal - $discount);
    $change = max(0, $amountPaid - $total);

    // Generate invoice number
    $invDate = date('Ymd');
    $lastInv = $db->query("SELECT MAX(id) as m FROM sales")->fetch()['m'] ?? 0;
    $invoiceNo = 'GOC-' . $invDate . '-' . str_pad($lastInv + 1, 4, '0', STR_PAD_LEFT);

    $db->beginTransaction();
    try {
        // Insert sale
        $saleStmt = $db->prepare("INSERT INTO sales (invoice_no,patient_id,cashier_id,subtotal,discount,total,payment_method,amount_paid,change_amount,notes,status) VALUES (?,?,?,?,?,?,?,?,?,?,'completed')");
        $saleStmt->execute([$invoiceNo,$patientId,$_SESSION['user_id'],$subtotal,$discount,$total,$payMethod,$amountPaid,$change,$notes]);
        $saleId = $db->lastInsertId();

        // Insert items + deduct stock
        foreach ($items as $item) {
            $db->prepare("INSERT INTO sale_items (sale_id,product_id,quantity,unit_price,total_price) VALUES (?,?,?,?,?)")
               ->execute([$saleId,$item['id'],$item['qty'],$item['price'],$item['total']]);

            // Deduct stock
            $prevStock = $db->prepare("SELECT stock_quantity FROM products WHERE id=?"); $prevStock->execute([$item['id']]); $prevStock = $prevStock->fetch()['stock_quantity'];
            $newStock = $prevStock - $item['qty'];
            $db->prepare("UPDATE products SET stock_quantity=? WHERE id=?")->execute([$newStock,$item['id']]);
            $db->prepare("INSERT INTO inventory_logs (product_id,type,quantity,previous_stock,new_stock,reason,user_id) VALUES (?,?,?,?,?,?,?)")
               ->execute([$item['id'],'stock_out',$item['qty'],$prevStock,$newStock,"Sale: $invoiceNo",$_SESSION['user_id']]);
        }

        $db->commit();
        echo json_encode(['success'=>true,'invoice_no'=>$invoiceNo,'sale_id'=>$saleId,'change'=>$change,'total'=>$total]);
    } catch (Exception $e) {
        $db->rollBack();
        echo json_encode(['success'=>false,'error'=>'Transaction failed: '.$e->getMessage()]);
    }
    exit;
}

// Search products AJAX
if (isset($_GET['search_products'])) {
    $q = '%' . sanitize($_GET['search_products']) . '%';
    $prods = $db->prepare("SELECT p.id, p.name, p.price, p.stock_quantity, p.image, c.name as category FROM products p JOIN categories c ON c.id=p.category_id WHERE (p.name LIKE ? OR c.name LIKE ?) AND p.status='active' AND p.stock_quantity>0 ORDER BY p.name LIMIT 20");
    $prods->execute([$q,$q]);
    header('Content-Type: application/json');
    echo json_encode($prods->fetchAll());
    exit;
}

// Get all products by category for initial load
$categories = $db->query("SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id=c.id AND p.status='active' AND p.stock_quantity>0) as prod_count FROM categories c WHERE c.status='active' ORDER BY c.name")->fetchAll();
$allProducts = $db->query("SELECT p.id,p.name,p.price,p.stock_quantity,p.image,c.name as category FROM products p JOIN categories c ON c.id=p.category_id WHERE p.status='active' AND p.stock_quantity>0 ORDER BY c.name,p.name")->fetchAll();

$patients = $db->query("SELECT id, full_name, phone FROM patients WHERE status='active' ORDER BY full_name")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="pos-layout" style="display:grid;grid-template-columns:1fr 380px;gap:20px;height:calc(100vh - 120px);">

  <!-- LEFT: Products Panel -->
  <div style="display:flex;flex-direction:column;gap:12px;overflow:hidden;">
    <!-- Search + Filter -->
    <div style="display:flex;gap:10px;flex-shrink:0;">
      <input type="text" id="productSearch" class="form-control" placeholder="🔍 Search products..." style="flex:1;">
      <select id="catFilter" class="form-select" style="width:180px;">
        <option value="">All Categories</option>
        <?php foreach ($categories as $cat): ?>
        <option value="<?= sanitize($cat['name']) ?>"><?= sanitize($cat['name']) ?> (<?= $cat['prod_count'] ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- Product Grid -->
    <div id="productGrid" style="flex:1;overflow-y:auto;display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px;align-content:start;padding-right:4px;">
      <?php foreach ($allProducts as $prod): ?>
      <div class="prod-card" data-id="<?= $prod['id'] ?>" data-name="<?= addslashes($prod['name']) ?>" data-price="<?= $prod['price'] ?>" data-stock="<?= $prod['stock_quantity'] ?>" data-cat="<?= addslashes($prod['category']) ?>"
           onclick="addToCart(this)"
           style="background:var(--bg-card);border:1px solid var(--border-color);border-radius:12px;padding:14px;cursor:pointer;transition:all .2s ease;"
           onmouseover="this.style.borderColor='var(--clr-primary)';this.style.boxShadow='0 4px 20px rgba(37,99,235,.15)'"
           onmouseout="this.style.borderColor='var(--border-color)';this.style.boxShadow='none'">
          <?php if($prod['image']): ?>
            <img src="<?= BASE_URL ?>assets/images/products/<?= $prod['image'] ?>" alt="Product" style="width:36px;height:36px;object-fit:cover;border-radius:10px;margin-bottom:10px;">
          <?php else: ?>
            <div style="width:36px;height:36px;background:linear-gradient(135deg,var(--clr-primary),var(--clr-secondary));border-radius:10px;display:flex;align-items:center;justify-content:center;margin-bottom:10px;">
              <i class="fas fa-glasses" style="color:#fff;font-size:.85rem;"></i>
            </div>
          <?php endif; ?>
        <div style="font-weight:700;font-size:.82rem;margin-bottom:4px;line-height:1.3"><?= sanitize($prod['name']) ?></div>
        <div style="font-size:.7rem;color:var(--text-muted);margin-bottom:8px"><?= sanitize($prod['category']) ?></div>
        <div style="display:flex;justify-content:space-between;align-items:center;">
          <div style="font-weight:800;color:var(--clr-primary);font-size:.9rem">₱<?= number_format($prod['price'],2) ?></div>
          <div style="font-size:.68rem;color:<?= $prod['stock_quantity']<=5?'var(--clr-warning)':'var(--text-muted)' ?>"><?= $prod['stock_quantity'] ?> left</div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- RIGHT: Cart Panel -->
  <div style="display:flex;flex-direction:column;gap:0;background:var(--bg-card);border:1px solid var(--border-color);border-radius:16px;overflow:hidden;">

    <!-- Cart Header -->
    <div style="padding:16px 20px;border-bottom:1px solid var(--border-light);display:flex;justify-content:space-between;align-items:center;background:linear-gradient(135deg,var(--clr-primary),var(--clr-secondary));color:#fff;">
      <h6 style="margin:0;font-size:.95rem;font-weight:700;"><i class="fas fa-shopping-cart me-2"></i>Cart <span id="cartCount" style="background:rgba(255,255,255,.25);padding:2px 8px;border-radius:10px;font-size:.75rem;margin-left:4px">0</span></h6>
      <button onclick="clearCart()" style="background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);color:#fff;border-radius:8px;padding:4px 12px;font-size:.75rem;cursor:pointer;font-family:'Poppins',sans-serif;">Clear</button>
    </div>

    <!-- Cart Items -->
    <div id="cartItems" style="flex:1;overflow-y:auto;padding:12px;">
      <div id="emptyCart" style="text-align:center;padding:40px 20px;color:var(--text-muted);">
        <i class="fas fa-shopping-cart" style="font-size:2rem;margin-bottom:12px;display:block;opacity:.3"></i>
        <p style="font-size:.82rem;margin:0">Cart is empty.<br>Click products to add them.</p>
      </div>
    </div>

    <!-- Summary Panel -->
    <div style="padding:16px;border-top:1px solid var(--border-light);background:var(--bg-hover);">
      <!-- Patient -->
      <div style="margin-bottom:12px;">
        <label style="font-size:.75rem;font-weight:600;color:var(--text-muted);margin-bottom:5px;display:block;">PATIENT (Optional)</label>
        <select id="patientSelect" class="form-select" style="font-size:.83rem;">
          <option value="">Walk-in Customer</option>
          <?php foreach ($patients as $pt): ?>
          <option value="<?= $pt['id'] ?>"><?= sanitize($pt['full_name']) ?> — <?= sanitize($pt['phone']??'') ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Discount -->
      <div style="display:flex;gap:8px;margin-bottom:12px;align-items:flex-end;">
        <div style="flex:1">
          <label style="font-size:.75rem;font-weight:600;color:var(--text-muted);margin-bottom:5px;display:block;">DISCOUNT (₱)</label>
          <input type="number" id="discountInput" class="form-control" value="0" min="0" step="0.01" style="font-size:.88rem;" oninput="recalculate()">
        </div>
        <div style="flex:1">
          <label style="font-size:.75rem;font-weight:600;color:var(--text-muted);margin-bottom:5px;display:block;">PAYMENT</label>
          <select id="paymentMethod" class="form-select" style="font-size:.83rem;">
            <option value="cash">Cash</option>
            <option value="gcash">GCash</option>
            <option value="card">Card</option>
          </select>
        </div>
      </div>

      <!-- Totals -->
      <div style="background:var(--bg-card);border-radius:10px;padding:12px;margin-bottom:12px;">
        <div style="display:flex;justify-content:space-between;font-size:.82rem;margin-bottom:6px;"><span style="color:var(--text-muted)">Subtotal</span><span id="subtotalDisplay">₱0.00</span></div>
        <div style="display:flex;justify-content:space-between;font-size:.82rem;margin-bottom:6px;"><span style="color:var(--clr-warning)">Discount</span><span id="discountDisplay" style="color:var(--clr-warning)">-₱0.00</span></div>
        <div style="display:flex;justify-content:space-between;font-size:1rem;font-weight:800;border-top:1px solid var(--border-light);padding-top:8px;"><span>TOTAL</span><span id="totalDisplay" style="color:var(--clr-primary)">₱0.00</span></div>
      </div>

      <!-- Amount Paid (cash only) -->
      <div id="cashPanel" style="margin-bottom:12px;">
        <label style="font-size:.75rem;font-weight:600;color:var(--text-muted);margin-bottom:5px;display:block;">AMOUNT RECEIVED (₱)</label>
        <input type="number" id="amountPaid" class="form-control" placeholder="0.00" step="0.01" min="0" oninput="recalculate()" style="font-size:.95rem;font-weight:700;">
        <div style="display:flex;justify-content:space-between;margin-top:6px;font-size:.85rem;">
          <span style="color:var(--text-muted)">Change:</span>
          <span id="changeDisplay" style="font-weight:800;color:var(--clr-success)">₱0.00</span>
        </div>
      </div>

      <button id="checkoutBtn" onclick="processCheckout()" class="btn btn-primary w-100" style="padding:14px;font-size:1rem;font-weight:700;" disabled>
        <i class="fas fa-cash-register"></i> Process Sale
      </button>
    </div>
  </div>
</div>

<!-- Receipt Modal -->
<div class="modal-overlay" id="receiptModal">
  <div class="modal-box" style="max-width:440px;">
    <div class="modal-header" style="background:linear-gradient(135deg,var(--clr-primary),var(--clr-secondary));color:#fff;border-radius:16px 16px 0 0;">
      <h5 style="margin:0;"><i class="fas fa-receipt me-2"></i>Transaction Complete!</h5>
      <button class="modal-close" onclick="closeModal('receiptModal')" style="color:#fff;opacity:.8"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body" style="text-align:center;padding:28px;">
      <div id="receiptContent"></div>
    </div>
    <div class="modal-footer">
      <button onclick="window.print()" class="btn btn-outline-primary"><i class="fas fa-print"></i> Print</button>
      <button onclick="closeModal('receiptModal');clearCart();" class="btn btn-primary"><i class="fas fa-plus"></i> New Sale</button>
    </div>
  </div>
</div>

<script>
// Cart state
let cart = [];
const formatPeso = v => '₱' + parseFloat(v).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');

// Product search & filter
const searchInput = document.getElementById('productSearch');
const catFilter   = document.getElementById('catFilter');
searchInput.addEventListener('input', filterProducts);
catFilter.addEventListener('change', filterProducts);

function filterProducts() {
  const q   = searchInput.value.toLowerCase();
  const cat = catFilter.value.toLowerCase();
  document.querySelectorAll('.prod-card').forEach(card => {
    const name = card.dataset.name.toLowerCase();
    const c    = card.dataset.cat.toLowerCase();
    const show = (!q || name.includes(q)) && (!cat || c === cat);
    card.style.display = show ? '' : 'none';
  });
}

// Add to cart
function addToCart(el) {
  const id    = parseInt(el.dataset.id);
  const name  = el.dataset.name;
  const price = parseFloat(el.dataset.price);
  const stock = parseInt(el.dataset.stock);

  const existing = cart.find(i => i.id === id);
  if (existing) {
    if (existing.qty >= stock) { showToast('Maximum stock reached!', 'warning'); return; }
    existing.qty++;
  } else {
    cart.push({ id, name, price, stock, qty: 1 });
  }
  renderCart();
  // Visual feedback
  el.style.background = 'rgba(37,99,235,.08)';
  setTimeout(() => el.style.background = 'var(--bg-card)', 300);
}

function removeFromCart(id) {
  cart = cart.filter(i => i.id !== id);
  renderCart();
}
function changeQty(id, delta) {
  const item = cart.find(i => i.id === id);
  if (!item) return;
  item.qty = Math.max(1, Math.min(item.stock, item.qty + delta));
  if (item.qty === 0) { removeFromCart(id); return; }
  renderCart();
}
function clearCart() {
  cart = []; renderCart();
}

function renderCart() {
  const container = document.getElementById('cartItems');
  const empty     = document.getElementById('emptyCart');
  const btn       = document.getElementById('checkoutBtn');
  document.getElementById('cartCount').textContent = cart.reduce((s,i) => s+i.qty, 0);

  if (cart.length === 0) {
    empty.style.display = '';
    container.innerHTML = ''; container.appendChild(empty);
    btn.disabled = true; recalculate(); return;
  }
  empty.style.display = 'none';
  container.innerHTML = '';

  cart.forEach(item => {
    const div = document.createElement('div');
    div.style.cssText = 'display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid var(--border-light);';
    div.innerHTML = `
      <div style="flex:1;overflow:hidden;">
        <div style="font-weight:600;font-size:.83rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${item.name}</div>
        <div style="font-size:.72rem;color:var(--text-muted)">${formatPeso(item.price)} each</div>
      </div>
      <div style="display:flex;align-items:center;gap:4px;">
        <button onclick="changeQty(${item.id},-1)" style="width:24px;height:24px;border-radius:6px;border:1px solid var(--border-color);background:var(--bg-hover);cursor:pointer;font-size:.7rem;color:var(--text-primary)">−</button>
        <span style="min-width:24px;text-align:center;font-weight:700;font-size:.85rem">${item.qty}</span>
        <button onclick="changeQty(${item.id},1)" style="width:24px;height:24px;border-radius:6px;border:1px solid var(--border-color);background:var(--bg-hover);cursor:pointer;font-size:.7rem;color:var(--text-primary)">+</button>
      </div>
      <div style="min-width:70px;text-align:right;font-weight:700;color:var(--clr-primary);font-size:.88rem">${formatPeso(item.price*item.qty)}</div>
      <button onclick="removeFromCart(${item.id})" style="background:none;border:none;color:var(--clr-danger);cursor:pointer;font-size:.75rem;padding:4px;">✕</button>
    `;
    container.appendChild(div);
  });

  btn.disabled = false;
  recalculate();
}

function recalculate() {
  const subtotal = cart.reduce((s,i) => s + i.price*i.qty, 0);
  const discount = parseFloat(document.getElementById('discountInput').value) || 0;
  const total    = Math.max(0, subtotal - discount);
  const paid     = parseFloat(document.getElementById('amountPaid').value) || 0;
  const change   = Math.max(0, paid - total);

  document.getElementById('subtotalDisplay').textContent = formatPeso(subtotal);
  document.getElementById('discountDisplay').textContent = '-' + formatPeso(discount);
  document.getElementById('totalDisplay').textContent    = formatPeso(total);
  document.getElementById('changeDisplay').textContent   = formatPeso(change);
}

// Payment method toggle
document.getElementById('paymentMethod').addEventListener('change', function() {
  document.getElementById('cashPanel').style.display = this.value === 'cash' ? '' : 'none';
});

async function processCheckout() {
  if (cart.length === 0) return;
  const total    = parseFloat(document.getElementById('totalDisplay').textContent.replace(/[^0-9.]/g,''));
  const paid     = parseFloat(document.getElementById('amountPaid').value) || 0;
  const payMethod= document.getElementById('paymentMethod').value;

  if (payMethod === 'cash' && paid < total) {
    showToast('Amount received is less than total!', 'danger'); return;
  }

  const btn = document.getElementById('checkoutBtn');
  btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';

  const form = new FormData();
  form.append('action', 'process_sale');
  form.append('items', JSON.stringify(cart.map(i => ({ id: i.id, qty: i.qty }))));
  form.append('patient_id', document.getElementById('patientSelect').value);
  form.append('payment_method', payMethod);
  form.append('discount', document.getElementById('discountInput').value || 0);
  form.append('amount_paid', paid);

  try {
    const res = await fetch('pos.php', { method:'POST', body: form });
    const data = await res.json();

    if (data.success) {
      showReceipt(data);
    } else {
      showToast(data.error || 'Sale failed!', 'danger');
      btn.disabled = false; btn.innerHTML = '<i class="fas fa-cash-register"></i> Process Sale';
    }
  } catch(e) {
    showToast('Network error!', 'danger');
    btn.disabled = false; btn.innerHTML = '<i class="fas fa-cash-register"></i> Process Sale';
  }
}

function showReceipt(data) {
  const html = `
    <div style="text-align:center;margin-bottom:20px;">
      <div style="width:60px;height:60px;background:linear-gradient(135deg,#059669,#0891B2);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;"><i class="fas fa-check" style="color:#fff;font-size:1.4rem;"></i></div>
      <h5 style="font-weight:800;color:var(--clr-success)">Payment Received!</h5>
    </div>
    <div style="background:var(--bg-hover);border-radius:12px;padding:16px;text-align:left;">
      <div style="display:flex;justify-content:space-between;margin-bottom:8px;"><span style="color:var(--text-muted);font-size:.82rem">Invoice No:</span><span style="font-family:monospace;font-weight:700;color:var(--clr-primary)">${data.invoice_no}</span></div>
      <div style="display:flex;justify-content:space-between;margin-bottom:8px;"><span style="color:var(--text-muted);font-size:.82rem">Total Amount:</span><span style="font-weight:800;color:var(--clr-primary);font-size:1.1rem">₱${parseFloat(data.total).toFixed(2)}</span></div>
      <div style="display:flex;justify-content:space-between;"><span style="color:var(--text-muted);font-size:.82rem">Change:</span><span style="font-weight:700;color:var(--clr-success);font-size:1rem">₱${parseFloat(data.change).toFixed(2)}</span></div>
    </div>
    <p style="color:var(--text-muted);font-size:.78rem;margin-top:12px;margin-bottom:0">Thank you for your purchase! 🙏</p>
  `;
  document.getElementById('receiptContent').innerHTML = html;
  openModal('receiptModal');
  // Refresh product cards to update stock (reload silently)
  setTimeout(() => location.reload(), 6000);
}

function showToast(msg, type='success') {
  const t = document.createElement('div');
  t.className = `alert alert-${type}`;
  t.style.cssText = 'position:fixed;top:80px;right:20px;z-index:9999;min-width:280px;animation:slideIn .3s ease;';
  t.innerHTML = `<i class="fas fa-${type==='success'?'check-circle':'exclamation-circle'} me-2"></i>${msg}`;
  document.body.appendChild(t);
  setTimeout(() => t.remove(), 4000);
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
