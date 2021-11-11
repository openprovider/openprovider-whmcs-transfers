<?php
    if (isset($views['error'])) {
        echo $views['error'];
    }
?>
<?php
$domains = $views['scheduled_transfer_domains'];
$page = $views['page'];
$numberPerPage = $views['number_per_page'];
$domainsNumber = $views['domains_number'];
$maxPagesList = $views['max_pages_list'];

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
    $queries = explode('&amp;', parse_url(basename($_SERVER['REQUEST_URI']))['query']);
    $queriesArray = [];
    foreach ($queries as $query) {
        $tmp = explode('=', $query);
        if (!$tmp[0]) {
            continue;
        }
        $queriesArray[$tmp[0]] = $tmp[1];
    }

    $queriesArray['p'] = $page;
    $queriesArray['n'] = $countPerPage;

    $result = '?';
    foreach ($queriesArray as $key => $value) {
        $result .= $key . '=' . $value . '&';
    }

    return $result;
}
?>

<a href="?module=openprovider_transfers&action=scheduled_domain_transfer_transfers" class="btn btn-default">
    Scheduled domain transfer transfers
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
<a href="?module=openprovider_transfers&action=export_csv" target="_blank" class="btn btn-default">
    Export as CSV
</a>
<br><br>
<a href="?module=openprovider_transfers&action=load_scheduled_transfers" class="btn btn-danger">
    Load scheduled transfers (2 - 5 minutes)
</a>
<a href="?module=openprovider_transfers&action=remove_all_fai" class="btn btn-danger">
    Remove only FAI scheduled domains
</a>
<a href="?module=openprovider_transfers&action=remove_all" class="btn btn-danger">
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
        <th><?php echo 'Transfer Scheduled At'; ?></th>
        <th><?php echo 'Status in Openprovider'?></th>
    </tr>
    <?php foreach ($domains as $item): ?>
        <tr>
            <td><?php echo $item['domain'] ?></td>
            <td><?php echo $item['scheduled_at'] ?></td>
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
