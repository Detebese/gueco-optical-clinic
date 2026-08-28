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

    if (empty($items)) {
        echo json_encode(['success'=>false,'error'=>'Cart is empty.']);
        exit;
    }

    // Calculate subtotal and build item details
    $subtotal = 0;
    $itemsDetailed = [];
    foreach ($items as &$item) {
        $prod = $db->prepare("SELECT * FROM products WHERE id=? AND status='active'");
        $prod->execute([$item['id']]); $prod = $prod->fetch();
        if (!$prod) { echo json_encode(['success'=>false,'error'=>'Product not found: '.$item['id']]); exit; }
        if ($prod['stock_quantity'] < $item['qty']) {
            echo json_encode(['success'=>false,'error'=>"Insufficient stock for: {$prod['name']}. Available: {$prod['stock_quantity']}"]); exit;
        }
        $item['price'] = (float)$prod['price'];
        $item['total'] = (float)($prod['price'] * $item['qty']);
        $item['name']  = $prod['name'];
        $subtotal += $item['total'];

        $itemsDetailed[] = [
            'id'    => $item['id'],
            'name'  => $prod['name'],
            'qty'   => (int)$item['qty'],
            'price' => (float)$prod['price'],
            'total' => (float)$item['total']
        ];
    }

    $total = max(0, $subtotal - $discount);
    $change = max(0, $amountPaid - $total);

    // Generate invoice number
    $invDate = date('Ymd');
    $lastInv = $db->query("SELECT MAX(id) as m FROM sales")->fetch()['m'] ?? 0;
    $invoiceNo = 'GOC-' . $invDate . '-' . str_pad($lastInv + 1, 4, '0', STR_PAD_LEFT);

    $patientName = 'Walk-in Customer';
    if ($patientId > 0) {
        $ptRow = $db->prepare("SELECT full_name FROM patients WHERE id=?");
        $ptRow->execute([$patientId]);
        $ptRow = $ptRow->fetch();
        if ($ptRow) {
            $patientName = $ptRow['full_name'];
        }
    }

    $dbPaymentMethod = in_array($payMethod, ['cash', 'gcash']) ? $payMethod : 'other';

    $db->beginTransaction();
    try {
        // Insert sale
        $saleStmt = $db->prepare("INSERT INTO sales (invoice_no,patient_id,cashier_id,subtotal,discount,total,payment_method,amount_paid,change_amount,status) VALUES (?,?,?,?,?,?,?,?,?,'completed')");
        $saleStmt->execute([$invoiceNo,$patientId,$_SESSION['user_id'],$subtotal,$discount,$total,$dbPaymentMethod,$amountPaid,$change]);
        $saleId = $db->lastInsertId();

        // Insert items + deduct stock
        foreach ($items as $item) {
            $db->prepare("INSERT INTO sale_items (sale_id,product_id,item_name,item_type,quantity,unit_price,total_price) VALUES (?,?,?, 'product', ?,?,?)")
               ->execute([$saleId,$item['id'],$item['name'],$item['qty'],$item['price'],$item['total']]);

            // Deduct stock
            $prevStock = $db->prepare("SELECT stock_quantity FROM products WHERE id=?"); 
            $prevStock->execute([$item['id']]); 
            $prevStock = (int)$prevStock->fetch()['stock_quantity'];
            $newStock = max(0, $prevStock - $item['qty']);
            
            $db->prepare("UPDATE products SET stock_quantity=? WHERE id=?")->execute([$newStock,$item['id']]);
            $db->prepare("INSERT INTO inventory_logs (product_id,type,quantity,previous_stock,new_stock,reason,reference_id,user_id) VALUES (?,?,?,?,?,?,?,?)")
               ->execute([$item['id'],'stock_out',$item['qty'],$prevStock,$newStock,"Sale: $invoiceNo",$saleId,$_SESSION['user_id']]);
        }

        $db->commit();
        echo json_encode([
            'success'        => true,
            'invoice_no'     => $invoiceNo,
            'sale_id'        => $saleId,
            'date'           => date('M d, Y h:i A'),
            'cashier'        => $_SESSION['user_name'] ?? 'Staff Cashier',
            'patient'        => $patientName,
            'items'          => $itemsDetailed,
            'subtotal'       => $subtotal,
            'discount'       => $discount,
            'total'          => $total,
            'payment_method' => strtoupper($payMethod),
            'amount_paid'    => $amountPaid,
            'change'         => $change
        ]);
    } catch (Exception $e) {
        $db->rollBack();
        echo json_encode(['success'=>false,'error'=>'Transaction failed: '.$e->getMessage()]);
    }
    exit;
}

// Search products AJAX
if (isset($_GET['search_products'])) {
    $q = '%' . sanitize($_GET['search_products']) . '%';
    $prods = $db->prepare("SELECT p.id, p.name, p.price, p.stock_quantity, c.name as category FROM products p JOIN categories c ON c.id=p.category_id WHERE (p.name LIKE ? OR c.name LIKE ?) AND p.status='active' AND p.stock_quantity>0 ORDER BY p.name LIMIT 20");
    $prods->execute([$q,$q]);
    header('Content-Type: application/json');
    echo json_encode($prods->fetchAll());
    exit;
}

// Get all products by category for initial load
$categories = $db->query("SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id=c.id AND p.status='active' AND p.stock_quantity>0) as prod_count FROM categories c WHERE c.status='active' ORDER BY c.name")->fetchAll();
$allProducts = $db->query("SELECT p.id,p.name,p.price,p.stock_quantity,c.name as category FROM products p JOIN categories c ON c.id=p.category_id WHERE p.status='active' AND p.stock_quantity>0 ORDER BY c.name,p.name")->fetchAll();

$patients = $db->query("SELECT id, full_name, phone FROM patients WHERE status='active' ORDER BY full_name")->fetchAll();
$selectedPatientId = (int)($_GET['patient_id'] ?? 0);

include __DIR__ . '/../includes/header.php';
?>

<style>
.pos-layout {
  display: grid;
  grid-template-columns: 1fr 420px;
  gap: 24px;
  align-items: start;
}
@media (max-width: 1024px) {
  .pos-layout {
    grid-template-columns: 1fr;
  }
}
.pos-cart-card {
  background: var(--bg-card);
  border: 1px solid var(--border-color);
  border-radius: 16px;
  overflow: hidden;
  box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
  position: sticky;
  top: 15px;
  display: flex;
  flex-direction: column;
}
.pos-cart-list {
  min-height: 100px;
  max-height: 215px; /* Fits 4 items cleanly, 5+ scrolls */
  overflow-y: auto;
  padding: 6px 14px;
  scrollbar-width: thin;
  scrollbar-color: var(--clr-primary) rgba(0, 0, 0, 0.05);
}
.pos-cart-list::-webkit-scrollbar {
  width: 6px;
}
.pos-cart-list::-webkit-scrollbar-track {
  background: rgba(0, 0, 0, 0.04);
  border-radius: 4px;
}
.pos-cart-list::-webkit-scrollbar-thumb {
  background: var(--clr-primary);
  border-radius: 4px;
}
.pos-cart-item {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 0;
  border-bottom: 1px solid var(--border-light);
  transition: background 0.15s ease;
}
.pos-cart-item:last-child {
  border-bottom: none;
}
.pos-qty-btn {
  width: 26px;
  height: 26px;
  border-radius: 6px;
  border: 1px solid var(--border-color);
  background: var(--bg-hover);
  cursor: pointer;
  font-size: 0.75rem;
  font-weight: bold;
  color: var(--text-primary);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  transition: all 0.15s ease;
}
.pos-qty-btn:hover {
  background: var(--clr-primary);
  color: #fff;
  border-color: var(--clr-primary);
}
.pos-remove-btn {
  background: none;
  border: none;
  color: var(--clr-danger);
  cursor: pointer;
  font-size: 0.85rem;
  padding: 4px 6px;
  border-radius: 4px;
  transition: background 0.15s ease;
}
.pos-remove-btn:hover {
  background: rgba(239, 68, 68, 0.12);
}
</style>

<div class="pos-layout">

  <!-- LEFT: Products Panel -->
  <div style="display:flex;flex-direction:column;gap:14px;">
    <!-- Search + Filter Bar -->
    <div style="display:flex;gap:10px;flex-shrink:0;">
      <input type="text" id="productSearch" class="form-control" placeholder="🔍 Search products by name..." style="flex:1;">
      <select id="catFilter" class="form-select" style="width:180px;">
        <option value="">All Categories</option>
        <?php foreach ($categories as $cat): ?>
        <option value="<?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($cat['name']) ?> (<?= $cat['prod_count'] ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- Product Grid -->
    <div id="productGrid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;align-content:start;">
      <?php foreach ($allProducts as $prod): ?>
      <div class="prod-card" 
           data-id="<?= $prod['id'] ?>" 
           data-name="<?= htmlspecialchars($prod['name'], ENT_QUOTES, 'UTF-8') ?>" 
           data-price="<?= $prod['price'] ?>" 
           data-stock="<?= $prod['stock_quantity'] ?>" 
           data-cat="<?= htmlspecialchars($prod['category'], ENT_QUOTES, 'UTF-8') ?>"
           onclick="addToCart(this)"
           style="background:var(--bg-card);border:1px solid var(--border-color);border-radius:12px;padding:14px;cursor:pointer;transition:all .2s ease;"
           onmouseover="this.style.borderColor='var(--clr-primary)';this.style.boxShadow='0 4px 20px rgba(37,99,235,.15)'"
           onmouseout="this.style.borderColor='var(--border-color)';this.style.boxShadow='none'">
        <div style="width:36px;height:36px;background:linear-gradient(135deg,var(--clr-primary),var(--clr-secondary));border-radius:10px;display:flex;align-items:center;justify-content:center;margin-bottom:10px;">
          <i class="fas fa-glasses" style="color:#fff;font-size:.85rem;"></i>
        </div>
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
  <div class="pos-cart-card">

    <!-- Cart Header -->
    <div style="padding:14px 18px;border-bottom:1px solid var(--border-light);display:flex;justify-content:space-between;align-items:center;background:linear-gradient(135deg,var(--clr-primary),var(--clr-secondary));color:#fff;flex-shrink:0;">
      <h6 style="margin:0;font-size:.95rem;font-weight:700;">
        <i class="fas fa-shopping-cart me-2"></i>Cart 
        <span id="cartCount" style="background:rgba(255,255,255,.25);padding:2px 8px;border-radius:10px;font-size:.75rem;margin-left:4px">0</span>
      </h6>
      <button type="button" onclick="clearCart()" style="background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);color:#fff;border-radius:8px;padding:4px 12px;font-size:.75rem;cursor:pointer;font-family:'Poppins',sans-serif;">Clear</button>
    </div>

    <!-- Cart Items List (max 4 visible before scroll) -->
    <div id="cartItems" class="pos-cart-list">
      <div id="emptyCart" style="text-align:center;padding:40px 16px;color:var(--text-muted);">
        <i class="fas fa-shopping-cart" style="font-size:2rem;margin-bottom:10px;display:block;opacity:.3"></i>
        <p style="font-size:.82rem;margin:0">Cart is empty.<br>Click products to add them.</p>
      </div>
    </div>

    <!-- Summary Panel -->
    <div style="padding:14px 16px;border-top:1px solid var(--border-light);background:var(--bg-hover);flex-shrink:0;">
      <!-- Patient Selection -->
      <div style="margin-bottom:10px;">
        <label style="font-size:.72rem;font-weight:600;color:var(--text-muted);margin-bottom:4px;display:block;">PATIENT (Optional)</label>
        <select id="patientSelect" class="form-select form-select-sm" style="font-size:.82rem;">
          <option value="">Walk-in Customer</option>
          <?php foreach ($patients as $pt): ?>
          <option value="<?= $pt['id'] ?>" <?= $pt['id'] === $selectedPatientId ? 'selected' : '' ?>><?= sanitize($pt['full_name']) ?> — <?= sanitize($pt['phone']??'') ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Discount & Payment Method -->
      <div style="display:flex;gap:8px;margin-bottom:10px;align-items:flex-end;">
        <div style="flex:1">
          <label style="font-size:.72rem;font-weight:600;color:var(--text-muted);margin-bottom:4px;display:block;">DISCOUNT (₱)</label>
          <input type="number" id="discountInput" class="form-control form-control-sm" value="0" min="0" step="0.01" style="font-size:.85rem;" oninput="recalculate()">
        </div>
        <div style="flex:1">
          <label style="font-size:.72rem;font-weight:600;color:var(--text-muted);margin-bottom:4px;display:block;">PAYMENT</label>
          <select id="paymentMethod" class="form-select form-select-sm" style="font-size:.82rem;">
            <option value="cash">Cash</option>
            <option value="gcash">GCash</option>
            <option value="card">Card</option>
          </select>
        </div>
      </div>

      <!-- Totals Card -->
      <div style="background:var(--bg-card);border:1px solid var(--border-color);border-radius:10px;padding:10px 12px;margin-bottom:10px;">
        <div style="display:flex;justify-content:space-between;font-size:.8rem;margin-bottom:4px;"><span style="color:var(--text-muted)">Subtotal</span><span id="subtotalDisplay" style="font-weight:600;">₱0.00</span></div>
        <div style="display:flex;justify-content:space-between;font-size:.8rem;margin-bottom:4px;"><span style="color:var(--clr-warning)">Discount</span><span id="discountDisplay" style="color:var(--clr-warning);font-weight:600;">-₱0.00</span></div>
        <div style="display:flex;justify-content:space-between;font-size:.95rem;font-weight:800;border-top:1px solid var(--border-light);padding-top:6px;"><span>TOTAL</span><span id="totalDisplay" style="color:var(--clr-primary)">₱0.00</span></div>
      </div>

      <!-- Amount Paid (cash only) -->
      <div id="cashPanel" style="margin-bottom:10px;">
        <label style="font-size:.72rem;font-weight:600;color:var(--text-muted);margin-bottom:4px;display:block;">AMOUNT RECEIVED (₱)</label>
        <input type="number" id="amountPaid" class="form-control form-control-sm" placeholder="0.00" step="0.01" min="0" oninput="recalculate()" style="font-size:.9rem;font-weight:700;">
        <div style="display:flex;justify-content:space-between;margin-top:5px;font-size:.82rem;">
          <span style="color:var(--text-muted)">Change:</span>
          <span id="changeDisplay" style="font-weight:800;color:var(--clr-success)">₱0.00</span>
        </div>
      </div>

      <button id="checkoutBtn" onclick="processCheckout()" class="btn btn-primary w-100" style="padding:10px;font-size:.92rem;font-weight:700;" disabled>
        <i class="fas fa-cash-register me-1"></i> Process Sale
      </button>
    </div>
  </div>
</div>

<!-- ============================================================ -->
<!-- OFFICIAL PDF RECEIPT PREVIEW MODAL                          -->
<!-- ============================================================ -->
<div class="modal fade" id="receiptModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content" style="background:var(--bg-card); border-radius:16px; border:1px solid var(--border-color); overflow:hidden;">
      <div class="modal-header" style="background:linear-gradient(135deg,var(--clr-primary),var(--clr-secondary)); color:#fff; padding:14px 20px;">
        <div class="d-flex align-items-center gap-2">
          <i class="fas fa-file-invoice fa-lg"></i>
          <div>
            <h5 class="modal-title fw-bold mb-0" style="font-size:1.05rem;" id="receiptModalTitle">Official Sales Receipt</h5>
            <small style="opacity:0.85;" id="receiptModalSubtitle">Gueco Optical Clinic</small>
          </div>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      
      <!-- Embedded PDF-like Receipt Viewer -->
      <div class="modal-body p-0" style="background:#525659;">
        <iframe id="receiptIframe" src="" style="width:100%; height:520px; border:none; display:block; background:#525659;"></iframe>
      </div>
      
      <div class="modal-footer" style="background:var(--bg-hover); padding:12px 20px; display:flex; justify-content:space-between; flex-wrap:wrap; gap:10px;">
        <button type="button" onclick="printReceiptIframe()" class="btn btn-primary px-4">
          <i class="fas fa-print me-1"></i> Print Receipt
        </button>
        <div class="d-flex gap-2">
          <a href="#" id="btnOpenReceiptTab" target="_blank" class="btn btn-outline-primary">
            <i class="fas fa-external-link-alt me-1"></i> Open PDF in Tab
          </a>
          <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">
            <i class="fas fa-plus me-1"></i> New Sale
          </button>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
// Cart state
let cart = [];
const formatPeso = v => '₱' + parseFloat(v).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');

function escapeHtml(str) {
  if (!str) return '';
  const d = document.createElement('div');
  d.textContent = str;
  return d.innerHTML;
}

// Product search & filter
const searchInput = document.getElementById('productSearch');
const catFilter   = document.getElementById('catFilter');
searchInput.addEventListener('input', filterProducts);
catFilter.addEventListener('change', filterProducts);

function filterProducts() {
  const q   = searchInput.value.toLowerCase().trim();
  const cat = catFilter.value.toLowerCase().trim();
  document.querySelectorAll('.prod-card').forEach(card => {
    const name = (card.dataset.name || '').toLowerCase();
    const c    = (card.dataset.cat || '').toLowerCase();
    const show = (!q || name.includes(q)) && (!cat || c === cat);
    card.style.display = show ? '' : 'none';
  });
}

// Add to cart
function addToCart(el) {
  const id    = parseInt(el.dataset.id, 10);
  const name  = el.dataset.name;
  const price = parseFloat(el.dataset.price);
  const stock = parseInt(el.dataset.stock, 10);

  if (!id || isNaN(id)) return;

  const existing = cart.find(i => i.id === id);
  if (existing) {
    if (existing.qty >= stock) { 
      showToast('Maximum stock reached for ' + name + '!', 'warning'); 
      return; 
    }
    existing.qty++;
  } else {
    cart.push({ id, name, price, stock, qty: 1 });
  }
  
  renderCart();
  
  // Visual click pulse
  el.style.transform = 'scale(0.97)';
  el.style.background = 'rgba(37,99,235,.12)';
  setTimeout(() => {
    el.style.transform = '';
    el.style.background = 'var(--bg-card)';
  }, 180);
}

function removeFromCart(id) {
  cart = cart.filter(i => i.id !== id);
  renderCart();
}

function changeQty(id, delta) {
  const item = cart.find(i => i.id === id);
  if (!item) return;
  
  const newQty = item.qty + delta;
  if (newQty <= 0) {
    removeFromCart(id);
    return;
  }
  
  if (newQty > item.stock) {
    showToast('Only ' + item.stock + ' items in stock!', 'warning');
    return;
  }
  
  item.qty = newQty;
  renderCart();
}

function clearCart() {
  cart = []; 
  renderCart();
}

function renderCart() {
  const container = document.getElementById('cartItems');
  const empty     = document.getElementById('emptyCart');
  const btn       = document.getElementById('checkoutBtn');
  
  const totalCount = cart.reduce((s,i) => s + i.qty, 0);
  document.getElementById('cartCount').textContent = totalCount;

  if (cart.length === 0) {
    if (empty) empty.style.display = '';
    container.innerHTML = ''; 
    if (empty) container.appendChild(empty);
    btn.disabled = true; 
    recalculate(); 
    return;
  }

  // Clear container
  container.innerHTML = '';

  // Render every unique product row
  cart.forEach(item => {
    const itemRow = document.createElement('div');
    itemRow.className = 'pos-cart-item';
    
    itemRow.innerHTML = `
      <div style="flex:1;min-width:0;">
        <div style="font-weight:600;font-size:.82rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--text-primary);" title="${escapeHtml(item.name)}">${escapeHtml(item.name)}</div>
        <div style="font-size:.7rem;color:var(--text-muted);">${formatPeso(item.price)} each</div>
      </div>
      <div style="display:flex;align-items:center;gap:5px;flex-shrink:0;">
        <button type="button" class="pos-qty-btn btn-qty-minus" data-id="${item.id}">−</button>
        <span style="min-width:22px;text-align:center;font-weight:700;font-size:.82rem;color:var(--text-primary);">${item.qty}</span>
        <button type="button" class="pos-qty-btn btn-qty-plus" data-id="${item.id}">+</button>
      </div>
      <div style="min-width:68px;text-align:right;font-weight:700;color:var(--clr-primary);font-size:.85rem;flex-shrink:0;">
        ${formatPeso(item.price * item.qty)}
      </div>
      <button type="button" class="pos-remove-btn btn-remove-item" data-id="${item.id}" title="Remove item">✕</button>
    `;
    
    // Add event listeners
    itemRow.querySelector('.btn-qty-minus').addEventListener('click', (e) => {
      e.stopPropagation();
      changeQty(item.id, -1);
    });
    
    itemRow.querySelector('.btn-qty-plus').addEventListener('click', (e) => {
      e.stopPropagation();
      changeQty(item.id, 1);
    });
    
    itemRow.querySelector('.btn-remove-item').addEventListener('click', (e) => {
      e.stopPropagation();
      removeFromCart(item.id);
    });

    container.appendChild(itemRow);
  });

  btn.disabled = false;
  recalculate();
}

function recalculate() {
  const subtotal = cart.reduce((s,i) => s + i.price * i.qty, 0);
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
  const subtotal  = cart.reduce((s,i) => s + i.price * i.qty, 0);
  const discount  = parseFloat(document.getElementById('discountInput').value) || 0;
  const total     = Math.max(0, subtotal - discount);
  const paid      = parseFloat(document.getElementById('amountPaid').value) || 0;
  const payMethod = document.getElementById('paymentMethod').value;

  if (payMethod === 'cash' && paid < total) {
    showToast('Amount received (₱' + paid.toFixed(2) + ') is less than total amount (₱' + total.toFixed(2) + ')!', 'danger'); 
    return;
  }

  const btn = document.getElementById('checkoutBtn');
  btn.disabled = true; 
  btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Processing...';

  const form = new FormData();
  form.append('action', 'process_sale');
  form.append('items', JSON.stringify(cart.map(i => ({ id: i.id, name: i.name, qty: i.qty }))));
  form.append('patient_id', document.getElementById('patientSelect').value);
  form.append('payment_method', payMethod);
  form.append('discount', discount);
  form.append('amount_paid', paid);

  try {
    const res = await fetch('pos.php', { method:'POST', body: form });
    const text = await res.text();
    let data;
    try {
      data = JSON.parse(text);
    } catch(err) {
      console.error('Non-JSON response:', text);
      showToast('Error processing sale: ' + text.substring(0, 100), 'danger');
      btn.disabled = false; 
      btn.innerHTML = '<i class="fas fa-cash-register me-1"></i> Process Sale';
      return;
    }

    if (data.success) {
      // 1. Reset inputs & clear cart
      clearCart();
      document.getElementById('amountPaid').value = '';
      document.getElementById('discountInput').value = '0';
      document.getElementById('patientSelect').value = '';
      recalculate();
      
      btn.disabled = false;
      btn.innerHTML = '<i class="fas fa-cash-register me-1"></i> Process Sale';

      // 2. Open Receipt in Tab & in Embedded Viewer Modal
      const receiptUrl = 'receipt.php?id=' + data.sale_id;
      
      // Auto open new tab with print trigger
      try {
        window.open(receiptUrl + '&auto_print=1', '_blank');
      } catch(e) {
        console.log('Popup blocked, falling back to modal');
      }

      // Load iframe in modal
      document.getElementById('receiptModalTitle').textContent = `Official Receipt · ${data.invoice_no}`;
      document.getElementById('receiptModalSubtitle').textContent = `Total: ${formatPeso(data.total)} · Customer: ${data.patient}`;
      document.getElementById('receiptIframe').src = receiptUrl;
      document.getElementById('btnOpenReceiptTab').href = receiptUrl;

      const modalEl = document.getElementById('receiptModal');
      const bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);
      bsModal.show();

      showToast('Sale processed successfully! Invoice #' + data.invoice_no, 'success');
    } else {
      showToast(data.error || 'Sale failed!', 'danger');
      btn.disabled = false; 
      btn.innerHTML = '<i class="fas fa-cash-register me-1"></i> Process Sale';
    }
  } catch(e) {
    showToast('Network error while processing sale!', 'danger');
    btn.disabled = false; 
    btn.innerHTML = '<i class="fas fa-cash-register me-1"></i> Process Sale';
  }
}

function printReceiptIframe() {
  const iframe = document.getElementById('receiptIframe');
  if (iframe && iframe.contentWindow) {
    iframe.contentWindow.focus();
    iframe.contentWindow.print();
  }
}

function showToast(msg, type='success') {
  const t = document.createElement('div');
  t.className = `alert alert-${type}`;
  t.style.cssText = 'position:fixed;top:80px;right:20px;z-index:9999;min-width:280px;animation:slideIn .3s ease;box-shadow:0 4px 16px rgba(0,0,0,0.15);';
  t.innerHTML = `<i class="fas fa-${type==='success'?'check-circle':'exclamation-circle'} me-2"></i>${msg}`;
  document.body.appendChild(t);
  setTimeout(() => t.remove(), 4000);
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
