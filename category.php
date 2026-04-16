<?php include 'functions.php'; include 'header.php';?>

<?php $products = getProducts(); ?>

<?php
$filter = $_GET['filter'];
$value = $_GET['value'];

$filtered = array_filter($products, function($p) use ($filter,$value){
    if($filter == 'category') return $p['category'] == $value;
    if($filter == 'type') return $p['product_type'] == $value;
    if($filter == 'therapy') return $p['therapeutic_class'] == $value;
    return false;
});
include 'footer.php';
?>

<h2><?= e($value) ?></h2>

<div class="grid">
<?php foreach($filtered as $p){ include 'card.php'; } ?>
</div>

<?php 
// ADD THIS LINE at bottom:
include 'footer.php'; 
?>