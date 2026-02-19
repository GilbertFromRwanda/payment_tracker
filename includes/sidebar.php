<aside class="sidebar">
    <ul>
        <?php
        $currentPage = basename($_SERVER['PHP_SELF']);
        // ['href' => 'deletion.php', 'label' => 'Payment Management', 'active' => true]
        $links = [
            'dashboard.php' => 'Dashboard',
            'sectors.php' => 'Manage Sectors',
            'import_customers.php' => 'Import Members',
             'customers.php' => 'Members',
            'import_payments.php' => 'Import Payments',
            'deletion.php'=>"Payment Management",
             'reports.php' => ' Reports',
        ];
        foreach ($links as $href => $label):
        ?>
        <li<?php echo ($currentPage === $href) ? ' class="active"' : ''; ?>><a href="<?php echo $href; ?>"><?php echo $label; ?></a></li>
        <?php endforeach; ?>
        <?php if (!empty($extraSidebarLinks)): ?>
            <?php foreach ($extraSidebarLinks as $extra): ?>
            <li<?php echo !empty($extra['active']) ? ' class="active"' : ''; ?>><a href="<?php echo $extra['href']; ?>"><?php echo $extra['label']; ?></a></li>
            <?php endforeach; ?>
        <?php endif; ?>
    </ul>
    <ul class="sidebar-bottom">
        <li><a href="change_password.php">Change Password</a></li>
        <li><a href="logout.php">Logout</a></li>
    </ul>
</aside>
