        </div><!-- /.content -->
    </main><!-- /.main -->
</div><!-- /.app -->

<script src="<?php echo BASE_URL; ?>/assets/vendor/marked.min.js"></script>
<script src="<?php echo BASE_URL; ?>/assets/vendor/katex.min.js"></script>
<script src="<?php echo BASE_URL; ?>/assets/vendor/auto-render.min.js"></script>

<?php include __DIR__ . '/ai-chat-drawer.php'; ?>
<?php include __DIR__ . '/theme-modal.php'; ?>

<script src="<?php echo BASE_URL; ?>/assets/js/core.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/core.js'); ?>"></script>
<script src="<?php echo BASE_URL; ?>/assets/js/theme-customizer.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/theme-customizer.js'); ?>"></script>
</body>
</html>
