<?php
define('BASE_URL', '../');
require_once __DIR__ . '/../config/functions.php';

// Allow saleslady and admin
requireRole('saleslady', 'admin');

$db = getDB();
$saleId = (int)($_GET['id'] ?? 0);

if ($saleId <= 0) {
    die("Invalid sale ID.");
}

$sale = $db->prepare("
    SELECT s.*, 
           p.full_name as patient_name, 
           p.phone as patient_phone, 
           p.email as patient_email, 
           p.address as patient_address,
           u.full_name as cashier_name
    FROM sales s
    LEFT JOIN patients p ON p.id = s.patient_id
    JOIN users u ON u.id = s.cashier_id
    WHERE s.id = ?
");
$sale->execute([$saleId]);
$sale = $sale->fetch();

if (!$sale) {
    die("Sale transaction not found.");
}

$items = $db->prepare("
    SELECT si.*, COALESCE(p.name, si.item_name) as product_name, c.name as category_name
    FROM sale_items si
    LEFT JOIN products p ON p.id = si.product_id
    LEFT JOIN categories c ON c.id = p.category_id
    WHERE si.sale_id = ?
    ORDER BY si.id ASC
");
$items->execute([$saleId]);
$items = $items->fetchAll();

// Fetch latest optical prescription for patient if available
$rx = null;
if (!empty($sale['patient_id'])) {
    $rxStmt = $db->prepare("
        SELECT rx.*, u.full_name as doctor_name 
        FROM prescriptions rx 
        LEFT JOIN users u ON u.id = rx.doctor_id 
        WHERE rx.patient_id = ? 
        ORDER BY rx.created_at DESC LIMIT 1
    ");
    $rxStmt->execute([$sale['patient_id']]);
    $rx = $rxStmt->fetch(PDO::FETCH_ASSOC);
}

$autoPrint = isset($_GET['auto_print']) && $_GET['auto_print'] == '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Receipt - <?= sanitize($sale['invoice_no']) ?> - Gueco Optical</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    :root {
      --clr-primary: #2563eb;
      --clr-secondary: #0891b2;
      --clr-success: #059669;
      --text-main: #0f172a;
      --text-muted: #64748b;
      --border-color: #e2e8f0;
    }

    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    body {
      background-color: #525659; /* Standard PDF viewer canvas color */
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
      color: var(--text-main);
      padding: 0;
      margin: 0;
      display: flex;
      flex-direction: column;
      align-items: center;
      min-height: 100vh;
    }

    /* PDF Viewer Top Action Bar */
    .pdf-toolbar {
      width: 100%;
      background: #323639;
      color: #f1f5f9;
      padding: 10px 24px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      box-shadow: 0 2px 8px rgba(0,0,0,0.3);
      position: sticky;
      top: 0;
      z-index: 100;
    }
    .pdf-toolbar-title {
      font-size: 0.9rem;
      font-weight: 600;
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .pdf-toolbar-actions {
      display: flex;
      gap: 10px;
      align-items: center;
    }
    .pdf-btn {
      background: #474b4e;
      border: 1px solid #5f6368;
      color: #fff;
      padding: 6px 14px;
      border-radius: 6px;
      font-size: 0.82rem;
      font-weight: 600;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      text-decoration: none;
      transition: background 0.15s ease;
    }
    .pdf-btn:hover {
      background: #5f6368;
      color: #fff;
    }
    .pdf-btn-primary {
      background: var(--clr-primary);
      border-color: var(--clr-primary);
    }
    .pdf-btn-primary:hover {
      background: #1d4ed8;
    }

    /* PDF Document Sheet */
    .receipt-sheet {
      background: #ffffff;
      width: 100%;
      max-width: 680px;
      margin: 28px auto 40px auto;
      padding: 40px;
      border-radius: 4px;
      box-shadow: 0 4px 25px rgba(0, 0, 0, 0.35);
      position: relative;
    }

    /* Clinic Header */
    .clinic-header {
      text-align: center;
      border-bottom: 2px solid var(--border-color);
      padding-bottom: 20px;
      margin-bottom: 24px;
      position: relative;
    }
    .clinic-logo-icon {
      width: 48px;
      height: 48px;
      background: linear-gradient(135deg, var(--clr-primary), var(--clr-secondary));
      border-radius: 12px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      color: #fff;
      font-size: 1.4rem;
      margin-bottom: 8px;
    }
    .clinic-title {
      font-size: 1.4rem;
      font-weight: 800;
      color: #0f172a;
      letter-spacing: 0.5px;
      text-transform: uppercase;
      margin-bottom: 2px;
    }
    .clinic-subtitle {
      font-size: 0.85rem;
      font-weight: 600;
      color: var(--clr-primary);
      margin-bottom: 4px;
    }
    .clinic-meta {
      font-size: 0.78rem;
      color: var(--text-muted);
      line-height: 1.4;
    }

    /* Receipt Badge & Status Stamp */
    .receipt-title-bar {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 20px;
      padding-bottom: 12px;
      border-bottom: 1px solid var(--border-color);
    }
    .receipt-type-title {
      font-size: 1.1rem;
      font-weight: 800;
      color: #0f172a;
      letter-spacing: 0.5px;
    }
    .paid-stamp {
      border: 2px solid var(--clr-success);
      color: var(--clr-success);
      font-size: 0.82rem;
      font-weight: 900;
      padding: 3px 12px;
      border-radius: 6px;
      text-transform: uppercase;
      letter-spacing: 1px;
      transform: rotate(-3deg);
    }

    /* Meta Details 2-Column */
    .meta-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 20px;
      margin-bottom: 24px;
      font-size: 0.82rem;
    }
    .meta-card {
      background: #f8fafc;
      border: 1px solid var(--border-color);
      border-radius: 8px;
      padding: 12px 16px;
    }
    .meta-card h6 {
      font-size: 0.72rem;
      font-weight: 700;
      color: var(--text-muted);
      text-transform: uppercase;
      letter-spacing: 0.5px;
      margin-bottom: 8px;
      border-bottom: 1px solid var(--border-color);
      padding-bottom: 4px;
    }
    .meta-row {
      display: flex;
      justify-content: space-between;
      margin-bottom: 5px;
      line-height: 1.4;
    }
    .meta-label {
      color: var(--text-muted);
    }
    .meta-val {
      font-weight: 600;
      color: #0f172a;
      text-align: right;
    }

    /* Items Table */
    .receipt-table {
      width: 100%;
      border-collapse: collapse;
      margin-bottom: 24px;
      font-size: 0.82rem;
    }
    .receipt-table th {
      background: #f1f5f9;
      color: #334155;
      font-weight: 700;
      text-transform: uppercase;
      font-size: 0.72rem;
      padding: 10px 12px;
      border-top: 1px solid var(--border-color);
      border-bottom: 2px solid var(--border-color);
      letter-spacing: 0.5px;
    }
    .receipt-table td {
      padding: 10px 12px;
      border-bottom: 1px solid var(--border-color);
      color: #1e293b;
    }
    .receipt-table tr:last-child td {
      border-bottom: 2px solid var(--border-color);
    }

    /* Financial Summary Calculation */
    .summary-section {
      display: flex;
      justify-content: flex-end;
      margin-bottom: 30px;
    }
    .summary-box {
      width: 280px;
      font-size: 0.85rem;
    }
    .summary-line {
      display: flex;
      justify-content: space-between;
      padding: 4px 0;
      color: #334155;
    }
    .summary-line.total-line {
      border-top: 2px solid #0f172a;
      border-bottom: 2px solid #0f172a;
      margin: 8px 0;
      padding: 8px 0;
      font-size: 1.1rem;
      font-weight: 800;
      color: #0f172a;
    }

    /* Receipt Footer */
    .receipt-footer {
      border-top: 1px dashed var(--border-color);
      padding-top: 20px;
      text-align: center;
      font-size: 0.75rem;
      color: var(--text-muted);
      line-height: 1.5;
    }
    .signatures-block {
      display: flex;
      justify-content: space-between;
      margin-top: 36px;
      padding-top: 10px;
      font-size: 0.75rem;
    }
    .sign-box {
      width: 180px;
      border-top: 1px solid #94a3b8;
      text-align: center;
      padding-top: 6px;
      color: #334155;
      font-weight: 600;
    }

    /* Print Stylesheet */
    @media print {
      @page {
        size: A4 portrait;
        margin: 10mm 15mm;
      }
      body {
        background: #fff !important;
        padding: 0 !important;
      }
      .pdf-toolbar {
        display: none !important;
      }
      .receipt-sheet {
        box-shadow: none !important;
        border: none !important;
        max-width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
      }
    }
  </style>
</head>
<body>

  <!-- Top PDF Action Bar -->
  <div class="pdf-toolbar">
    <div class="pdf-toolbar-title">
      <i class="fas fa-file-invoice text-primary"></i>
      <span>Official Receipt &middot; <?= sanitize($sale['invoice_no']) ?></span>
    </div>
    <div class="pdf-toolbar-actions">
      <button type="button" onclick="window.print()" class="pdf-btn pdf-btn-primary">
        <i class="fas fa-print"></i> Print Receipt
      </button>
      <a href="pos.php" class="pdf-btn">
        <i class="fas fa-shopping-cart"></i> Back to POS
      </a>
      <button type="button" onclick="window.close()" class="pdf-btn">
        <i class="fas fa-times"></i> Close
      </button>
    </div>
  </div>

  <!-- PDF Document Canvas -->
  <div class="receipt-sheet" id="receiptContent">
    
    <!-- Clinic Header -->
    <div class="clinic-header">
      <div class="clinic-logo-icon">
        <i class="fas fa-glasses"></i>
      </div>
      <h1 class="clinic-title">Gueco Optical Clinic</h1>
      <div class="clinic-subtitle">Professional Eye Care & Optical Services</div>
      <div class="clinic-meta">
        Angeles City, Pampanga, Philippines<br>
        Tel: (045) 123-4567 &middot; Mobile: 0917-123-4567 &middot; Email: guecooptical@gmail.com
      </div>
    </div>

    <!-- Title & Status Stamp -->
    <div class="receipt-title-bar">
      <div>
        <div class="receipt-type-title">OFFICIAL SALES RECEIPT</div>
        <div style="font-family: monospace; font-size: 0.85rem; color: var(--clr-primary); font-weight: 700; margin-top: 2px;">
          INVOICE #<?= sanitize($sale['invoice_no']) ?>
        </div>
      </div>
      <div class="paid-stamp">
        <i class="fas fa-check-circle me-1"></i> PAID
      </div>
    </div>

    <!-- 2-Column Metadata -->
    <div class="meta-grid">
      <!-- Billed To -->
      <div class="meta-card">
        <h6>Customer Information</h6>
        <div class="meta-row">
          <span class="meta-label">Patient/Customer:</span>
          <span class="meta-val"><?= sanitize($sale['patient_name'] ?? 'Walk-in Customer') ?></span>
        </div>
        <div class="meta-row">
          <span class="meta-label">Contact:</span>
          <span class="meta-val"><?= sanitize($sale['patient_phone'] ?: 'N/A') ?></span>
        </div>
        <?php if (!empty($sale['patient_address'])): ?>
        <div class="meta-row">
          <span class="meta-label">Address:</span>
          <span class="meta-val"><?= sanitize($sale['patient_address']) ?></span>
        </div>
        <?php endif; ?>
      </div>

      <!-- Transaction Info -->
      <div class="meta-card">
        <h6>Transaction Details</h6>
        <div class="meta-row">
          <span class="meta-label">Date & Time:</span>
          <span class="meta-val"><?= date('M d, Y · h:i A', strtotime($sale['created_at'])) ?></span>
        </div>
        <div class="meta-row">
          <span class="meta-label">Attending Cashier:</span>
          <span class="meta-val"><?= sanitize($sale['cashier_name']) ?></span>
        </div>
        <div class="meta-row">
          <span class="meta-label">Payment Method:</span>
          <span class="meta-val" style="text-transform: uppercase; color: var(--clr-primary);"><?= sanitize($sale['payment_method']) ?></span>
        </div>
      </div>
    </div>

    <!-- Itemized Table -->
    <table class="receipt-table">
      <thead>
        <tr>
          <th style="width: 40px; text-align: center;">#</th>
          <th>Item Description</th>
          <th style="width: 120px;">Category</th>
          <th style="width: 60px; text-align: center;">Qty</th>
          <th style="width: 90px; text-align: right;">Unit Price</th>
          <th style="width: 100px; text-align: right;">Total Amount</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($items as $idx => $it): ?>
        <tr>
          <td style="text-align: center; color: var(--text-muted);"><?= $idx + 1 ?></td>
          <td style="font-weight: 600;"><?= sanitize($it['product_name']) ?></td>
          <td style="color: var(--text-muted); font-size: 0.75rem;"><?= sanitize($it['category_name'] ?: 'General') ?></td>
          <td style="text-align: center; font-weight: 700;"><?= $it['quantity'] ?></td>
          <td style="text-align: right;"><?= formatCurrency($it['unit_price']) ?></td>
          <td style="text-align: right; font-weight: 700; color: #0f172a;"><?= formatCurrency($it['total_price']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <?php if ($rx): ?>
    <!-- Optical Prescription Details -->
    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px 14px;margin-bottom:20px;">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;border-bottom:1px solid #e2e8f0;padding-bottom:5px;">
        <span style="font-size:0.75rem;font-weight:800;text-transform:uppercase;color:var(--clr-primary);letter-spacing:0.5px;"><i class="fas fa-glasses me-1"></i> Patient Optical Prescription</span>
        <span style="font-size:0.72rem;color:var(--text-muted);font-weight:600;">Prescribing Doctor: Dr. <?= sanitize($rx['doctor_name'] ?? 'Optometrist') ?> &middot; <?= date('M d, Y', strtotime($rx['created_at'])) ?></span>
      </div>
      <table style="width:100%;font-size:0.78rem;border-collapse:collapse;text-align:center;">
        <thead>
          <tr style="background:#eef2f6;color:#334155;font-weight:700;">
            <th style="padding:6px 8px;text-align:left;">Eye</th>
            <th style="padding:6px 8px;">SPH</th>
            <th style="padding:6px 8px;">CYL</th>
            <th style="padding:6px 8px;">AXIS</th>
            <th style="padding:6px 8px;">ADD</th>
            <th style="padding:6px 8px;">PD</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td style="padding:6px 8px;text-align:left;font-weight:700;color:#0f172a;">OD (Right Eye)</td>
            <td style="padding:6px 8px;font-weight:600;"><?= sanitize($rx['od_sphere'] ?: '0.00') ?></td>
            <td style="padding:6px 8px;font-weight:600;"><?= sanitize($rx['od_cylinder'] ?: '0.00') ?></td>
            <td style="padding:6px 8px;"><?= sanitize($rx['od_axis'] ?: '—') ?></td>
            <td style="padding:6px 8px;"><?= sanitize($rx['add_power'] ?: '—') ?></td>
            <td style="padding:6px 8px;font-weight:700;color:var(--clr-primary);" rowspan="2"><?= sanitize($rx['pd'] ?: '—') ?></td>
          </tr>
          <tr style="background:#fafafa;">
            <td style="padding:6px 8px;text-align:left;font-weight:700;color:#0f172a;">OS (Left Eye)</td>
            <td style="padding:6px 8px;font-weight:600;"><?= sanitize($rx['os_sphere'] ?: '0.00') ?></td>
            <td style="padding:6px 8px;font-weight:600;"><?= sanitize($rx['os_cylinder'] ?: '0.00') ?></td>
            <td style="padding:6px 8px;"><?= sanitize($rx['os_axis'] ?: '—') ?></td>
            <td style="padding:6px 8px;"><?= sanitize($rx['add_power'] ?: '—') ?></td>
          </tr>
        </tbody>
      </table>
      <?php if (!empty($rx['notes'])): ?>
      <div style="font-size:0.72rem;color:var(--text-muted);margin-top:6px;font-style:italic;padding-top:4px;border-top:1px dashed #e2e8f0;">
        <strong>Clinical Recommendation:</strong> <?= sanitize($rx['notes']) ?>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Totals Summary -->
    <div class="summary-section">
      <div class="summary-box">
        <div class="summary-line">
          <span class="meta-label">Subtotal:</span>
          <span style="font-weight: 600;"><?= formatCurrency($sale['subtotal']) ?></span>
        </div>
        <?php if ($sale['discount'] > 0): ?>
        <div class="summary-line" style="color: #d97706;">
          <span>Discount Applied:</span>
          <span style="font-weight: 600;">-<?= formatCurrency($sale['discount']) ?></span>
        </div>
        <?php endif; ?>
        <div class="summary-line total-line">
          <span>GRAND TOTAL:</span>
          <span style="color: var(--clr-primary);"><?= formatCurrency($sale['total']) ?></span>
        </div>
        <div class="summary-line">
          <span class="meta-label">Amount Tendered (<?= strtoupper(sanitize($sale['payment_method'])) ?>):</span>
          <span style="font-weight: 600;"><?= formatCurrency($sale['amount_paid']) ?></span>
        </div>
        <div class="summary-line" style="color: var(--clr-success); font-weight: 700;">
          <span>Change:</span>
          <span><?= formatCurrency($sale['change_amount']) ?></span>
        </div>
      </div>
    </div>

    <!-- Signatures -->
    <div class="signatures-block">
      <div class="sign-box">
        Customer / Patient Signature
      </div>
      <div class="sign-box">
        Authorized Cashier: <?= sanitize($sale['cashier_name']) ?>
      </div>
    </div>

    <!-- Footer Notice -->
    <div class="receipt-footer" style="margin-top: 24px;">
      <p style="font-weight: 700; color: #0f172a; margin-bottom: 3px;">Thank you for trusting Gueco Optical Clinic!</p>
      <p>Please present this receipt for any warranty claims, prescription verifications, or optical adjustments.</p>
    </div>

  </div>

  <?php if ($autoPrint): ?>
  <script>
    window.addEventListener('DOMContentLoaded', () => {
      setTimeout(() => {
        window.print();
      }, 400);
    });
  </script>
  <?php endif; ?>

</body>
</html>
