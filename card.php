<?php if(empty($p)) return; ?>
<article class="card">
    <div class="card-image-container">
        <?php if(!empty($p['image_url'])): ?><img src="<?=e($p['image_url'])?>" alt="<?=e($p['product_name'])?>"><?php endif; ?>
        <span class="availability-badge <?= strtolower($p['availability']??'')==='in stock' ? 'in-stock' : 'backorder' ?>"><?=e($p['availability'])?></span>
    </div>
    <div class="card-body">
        <h3 class="card-title"><a href="product.php?slug=<?=e($p['slug'])?>"><?=e($p['product_name'])?></a></h3>
        <div class="card-meta">
            <div><span class="meta-label">CAS</span><span class="meta-value"><?=e($p['cas_number'])?></span></div>
            <div><span class="meta-label">Purity</span><span class="meta-value"><?=e($p['purity'])?></span></div>
            <div><span class="meta-label">MW</span><span class="meta-value"><?=e($p['molecular_weight'])?></span></div>
            <div><span class="meta-label">Formula</span><span class="meta-value"><?=e($p['molecular_formula'])?></span></div>
        </div>
        <div class="card-footer">
            <span class="tag" style="font-size:0.7rem; background:#f1f5f9; padding:4px 8px; border-radius:4px;"><?=e($p['product_type'])?></span>
            <a href="product.php?slug=<?=e($p['slug'])?>" class="btn btn-outline btn-sm">View Details →</a>
        </div>
    </div>
</article>