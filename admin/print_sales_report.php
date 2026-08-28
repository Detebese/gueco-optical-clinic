<?php
require_once __DIR__ . '/../config/functions.php';
requireRole('admin');

$filterFrom = $_GET['from'] ?? date('Y-m-01');
$filterTo   = $_GET['to'] ?? date('Y-m-d');

// Fetch all sales ordered by date
$stmt = $db->prepare("
    SELECT s.*, u.full_name as cashier_name
    FROM sales s
    JOIN users u ON u.id=s.cashier_id
    WHERE DATE(s.created_at) BETWEEN ? AND ? AND s.status='completed'
    ORDER BY s.created_at ASC
");
$stmt->execute([$filterFrom, $filterTo]);
$sales = $stmt->fetchAll();

// Group by month
$grouped = [];
foreach ($sales as $s) {
    $month = date('n/Y', strtotime($s['created_at']));
    $grouped[$month][] = $s;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Sale Report</title>
<style>
  body { font-family: "Times New Roman", Times, serif; color: #000; background: #fff; margin: 0; padding: 20px; font-size: 14px; }
  .report-container { max-width: 800px; margin: 0 auto; border: 1px solid #000; padding: 20px; }
  .header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; }
  .header-left { width: 250px; }
  .header-center { flex-grow: 1; text-align: center; padding-top: 10px; }
  .header-title { font-size: 38px; font-weight: bold; }
  .header-right { width: 200px; }
  .logo-box { border: 1px solid #000; padding: 25px 0; text-align: center; margin-bottom: 15px; }
  .company-info { font-weight: bold; font-size: 18px; margin-bottom: 8px; }
  .address-info { font-size: 12px; line-height: 1.4; }
  .date-info { text-align: right; font-size: 12px; line-height: 1.4; }
  
  table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 13px; }
  th, td { border: 1px solid #000; padding: 4px 6px; text-align: right; }
  th { text-align: center; font-weight: normal; }
  .col-text { text-align: left; }
  
  .footer { margin-top: 60px; display: flex; justify-content: space-between; }
  .sig-block { font-weight: bold; font-size: 15px; display: flex; align-items: end; }
  .signature-line { margin-left: 5px; width: 200px; border-bottom: 1px solid #000; }
  
  @media print {
      body { padding: 0; }
      .report-container { border: none; padding: 0; }
  }
</style>
</head>
<body onload="window.print()">

<div class="report-container">
    <div class="header">
        <div class="header-left">
            <div style="height:40px;"></div> <!-- spacer to push text down like image -->
            <div class="company-info">Your Company Name</div>
            <div class="address-info">
                Street Address<br>
                City, State, Zip Code<br>
                Phone Number, Website, Email Address etc
            </div>
        </div>
        <div class="header-center">
            <div class="header-title">Sale Report</div>
        </div>
        <div class="header-right">
            <div class="logo-box">Company<br>Logo Here</div>
            <div class="date-info">
                Date:<br>
                From: <?= date('n/j/Y', strtotime($filterFrom)) ?><br>
                To: <?= date('n/j/Y', strtotime($filterTo)) ?>
            </div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Month</th>
                <th>Date</th>
                <th>Discount</th>
                <th>Invoice #</th>
                <th>Sales Rep.</th>
                <th>Total</th>
                <th>Paid</th>
                <th>Change</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $grandTotal = 0;
            $grandPaid = 0;
            $grandChange = 0;
            $grandDiscount = 0;

            if (empty($grouped)): ?>
            <tr><td colspan="8" style="text-align:center;">No sales found for this period.</td></tr>
            <?php else:
            foreach ($grouped as $month => $monthSales): 
                $monthTotal = 0;
                $monthPaid = 0;
                $monthChange = 0;
                $monthDiscount = 0;
                
                foreach ($monthSales as $i => $s):
                    $monthTotal += $s['total'];
                    $monthPaid += $s['amount_paid'];
                    $monthChange += $s['change_amount'];
                    $monthDiscount += $s['discount'];
            ?>
            <tr>
                <td class="col-text"><?= $i === 0 ? $month : '-----' ?></td>
                <td class="col-text"><?= date('n/j/Y', strtotime($s['created_at'])) ?></td>
                <td><?= number_format($s['discount'], 2) ?></td>
                <td class="col-text"><?= htmlspecialchars($s['invoice_number']) ?></td>
                <td class="col-text"><?= htmlspecialchars($s['cashier_name']) ?></td>
                <td><?= number_format($s['total'], 2) ?></td>
                <td><?= number_format($s['amount_paid'], 2) ?></td>
                <td><?= number_format($s['change_amount'], 2) ?></td>
            </tr>
            <?php endforeach; 
                $grandTotal += $monthTotal;
                $grandPaid += $monthPaid;
                $grandChange += $monthChange;
                $grandDiscount += $monthDiscount;
            ?>
            <tr>
                <td class="col-text"><?= $month ?> Total</td>
                <td></td>
                <td><?= number_format($monthDiscount, 2) ?></td>
                <td></td>
                <td></td>
                <td><?= number_format($monthTotal, 2) ?></td>
                <td><?= number_format($monthPaid, 2) ?></td>
                <td><?= number_format($monthChange, 2) ?></td>
            </tr>
            <?php endforeach; 
            endif; ?>
            
            <?php for($i=0; $i<6; $i++): ?>
            <tr><td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
            <?php endfor; ?>

            <tr>
                <td class="col-text">Total</td>
                <td></td>
                <td><?= number_format($grandDiscount, 2) ?></td>
                <td></td>
                <td></td>
                <td><?= number_format($grandTotal, 2) ?></td>
                <td><?= number_format($grandPaid, 2) ?></td>
                <td><?= number_format($grandChange, 2) ?></td>
            </tr>
        </tbody>
    </table>

    <div class="footer">
        <div class="sig-block">
            Signed BY: <div class="signature-line"></div>
        </div>
        <div class="sig-block">
            Submitted By: <div class="signature-line"></div>
        </div>
    </div>
</div>

</body>
</html>



