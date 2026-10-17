<?php include_once __DIR__ . '/templates/header.php'; ?>

<main>
    <section class="hero">
        <div class="wrap">
            <div>
                <h1>T-shirts para quem vive de código.</h1>
                <p>Node, Laravel, Go, PHP e mais. Algodão macio, estampas simples e preços em dólares.</p>
                <a href="#loja" class="btn">Ver t-shirts</a>
            </div>
            <div class="note" aria-hidden="true">
                <span>coleção</span>
                <strong>01</strong>
                <small>8 t-shirts · USD</small>
            </div>
        </div>
    </section>

    <section id="loja">
        <div class="wrap">
            <div class="section-head">
                <h2>Todas as t-shirts.</h2>
                <p>Preços em dólares americanos.</p>
            </div>

            <div class="grid" id="grid"></div>
            <p class="empty" id="empty">Nenhuma t-shirt encontrada. Tenta outra palavra.</p>
        </div>
    </section>
</main>

<?php include_once __DIR__ . '/templates/cart.php'; ?>
<?php include_once __DIR__ . '/templates/footer.php'; ?>