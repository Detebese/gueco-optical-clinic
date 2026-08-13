    </div><!-- /.page-content -->
  </div><!-- /.main-content -->
</div><!-- /.app-wrapper -->

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<!-- Main JS -->
<script src="<?= BASE_URL ?>assets/js/main.js?v=<?= time() ?>"></script>
<?php if (isset($extraScripts)) echo $extraScripts; ?>
</body>
</html>
