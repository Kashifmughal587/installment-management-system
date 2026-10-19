        </main>

        <footer class="app-footer">
            <span>&copy; <?= date('Y') ?> <?= e(business_settings()['shop_name'] ?? APP_NAME) ?></span>
            <span class="text-muted">Installment Management System</span>
        </footer>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= e(asset('js/app.js')) ?>?v=3"></script>
<?php if (!empty($pageScripts)) { echo $pageScripts; } ?>
</body>
</html>
