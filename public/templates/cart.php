<label for="cart-toggle" class="overlay"></label>
<aside class="sidebar" aria-label="Carrinho">
    <header>
        <div><small>Seu pedido</small>
            <h2>Carrinho</h2>
        </div>
        <label for="cart-toggle" class="icon-btn" aria-label="Fechar carrinho"><i class="fa-solid fa-xmark"></i></label>
    </header>
    <div class="items" id="items"></div>
    <footer>
        <form action="/checkout" method="POST">
            <?= $this->csrfField() ?>
            <div class="total"><span>Total</span><b id="total"></b></div>
            <button id="btn-checkout" class="btn full" aria-disabled="true" tabindex="-1">Continuar para pagamento</button>
        </form>
    </footer>
</aside>