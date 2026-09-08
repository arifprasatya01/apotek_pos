<?php if (!defined('APP_RUNNING')) { http_response_code(403); exit('403 Forbidden'); } ?>
    </main><!-- /.content -->
  </div><!-- /.main -->
</div><!-- /.layout -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/app.js"></script>
<?php if (!empty($extra_js)) echo $extra_js; ?>
</body>
</html>
