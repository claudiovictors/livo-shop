<?php include_once __DIR__ . '/templates/header.php'; ?>
<main class="page">
    <div class="wrap">
        <h1>Contacto.</h1>
        <p class="lead">Dúvidas sobre tamanhos, pedidos ou envios? Escreve-nos e respondemos em até 2 dias úteis.</p>

        <div class="contact">
            <form class="panel" action="#" method="post">
                <h2>Envia uma mensagem</h2>
                <div class="row">
                    <label class="field"><span>Nome</span><input name="name" required autocomplete="name"></label>
                    <label class="field"><span>Email</span><input type="email" name="email" required
                            autocomplete="email"></label>
                </div>
                <label class="field"><span>Assunto</span>
                    <select name="subject">
                        <option>Dúvida sobre produto</option>
                        <option>Meu pedido</option>
                        <option>Troca ou devolução</option>
                        <option>Outro</option>
                    </select>
                </label>
                <label class="field"><span>Mensagem</span><textarea name="message" required></textarea></label>
                <button type="submit" class="btn full">Enviar mensagem</button>
            </form>

            <aside class="panel">
                <h2>Outros contactos</h2>
                <ul class="info">
                    <li><i class="fa-regular fa-envelope"></i>
                        <div>barrosclaudio147@gmail.com<small>Email</small></div>
                    </li>
                    <li><i class="fa-brands fa-instagram"></i>
                        <div>@claudio_victor.dev<small>Instagram</small></div>
                    </li>
                    <li><i class="fa-regular fa-clock"></i>
                        <div>Seg–Sex, 9h–17h<small>Atendimento</small></div>
                    </li>
                </ul>
            </aside>
        </div>
    </div>
</main>
<?php include_once __DIR__ . '/templates/cart.php'; ?>
<?php include_once __DIR__ . '/templates/footer.php'; ?>