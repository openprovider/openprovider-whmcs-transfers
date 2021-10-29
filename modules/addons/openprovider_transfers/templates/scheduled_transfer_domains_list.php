<?php
    if (isset($view['error'])) {
        echo $view['error'];
    }
?>
<?php
$domains = $view['scheduled_transfer_domains'];
$page = $view['page'];
$numberPerPage = $view['number_per_page'];
$domainsNumber = $view['domains_number'];
$maxPagesList = $view['max_pages_list'];

$pageCount = ceil($domainsNumber / $numberPerPage);
$firstPage = $page - (int) ($maxPagesList / 2);

if ($firstPage <= 1) {
    $firstPage = 1;
} else {
    if ($pageCount - $firstPage < $maxPagesList) {
        $firstPage = $pageCount - $maxPagesList + 1;
        if ($firstPage <= 1) {
            $firstPage = 1;
        }
    }
}
$lastPage = $firstPage + $maxPagesList - 1;
if ($lastPage > $pageCount) {
    $lastPage = $pageCount;
}

function generatePaginationUrl($page, $countPerPage) {
    return "?module=openprovider_transfers&p={$page}&n={$countPerPage}";
}
?>

<a href="?module=openprovider_transfers&action=scheduled_domain_transfer_transfers" class="btn btn-default">
    scheduled domain transfer transfers
</a>
<a href="?module=openprovider_transfers&action=requested_transfers" class="btn btn-default">
    Requested transfers
</a>
<a href="?module=openprovider_transfers&action=failed_transfers" class="btn btn-default">
    Failed transfers
</a>
<a href="?module=openprovider_transfers&action=completed_transfers" class="btn btn-default">
    Completed transfers
</a>
<a href="?module=openprovider_transfers&action=export_csv" class="btn btn-default">
    Export as CSV
</a>
<a href="?module=openprovider_transfers&action=remove_all" class="btn btn-danger    ">
    Remove list
</a>

<br><br>

<?php if ($firstPage != 1) : ?>
    <a href="<?php echo generatePaginationUrl($firstPage, $numberPerPage)?>"><</a>&#32;|
<?php endif; ?>

<?php for ($i = $firstPage; $i <= $lastPage; $i++) : ?>
    &#32;<a href="<?php echo generatePaginationUrl($i, $numberPerPage)?>"><?php echo $i ?></a>&#32;|
<?php endfor; ?>

<?php if ($lastPage < $pageCount) : ?>
    &#32;<a href="<?php echo generatePaginationUrl($lastPage, $numberPerPage)?>">></a>
<?php endif; ?>



<table width="100%" cellspacing="1" cellpadding="3" border="0" class="datatable">
    <tbody>
    <tr>
        <th><?php echo 'Domain Name'; ?></th>
        <th><?php echo 'Finished Transfer Date'; ?></th>
        <th><?php echo 'OP Status'?></th>
    </tr>
    <?php foreach ($domains as $item): ?>
        <tr>
            <td><?php echo $item['domain'] ?></td>
            <td><?php echo $item['finished_transfer_date'] ?></td>
            <td><?php echo $item['op_status'] ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php if ($firstPage != 1) : ?>
    <a href="<?php echo generatePaginationUrl($firstPage, $numberPerPage)?>"><</a>&#32;|
<?php endif; ?>

<?php for ($i = $firstPage; $i <= $lastPage; $i++) : ?>
    &#32;<a href="<?php echo generatePaginationUrl($i, $numberPerPage)?>"><?php echo $i ?></a>&#32;|
<?php endfor; ?>

<?php if ($lastPage < $pageCount) : ?>
    &#32;<a href="<?php echo generatePaginationUrl($lastPage, $numberPerPage)?>">></a>
<?php endif; ?>
