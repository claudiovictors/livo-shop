<!DOCTYPE html>
<html lang="pt-AO">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Livo Shop — T-shirts</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="/public/assets/css/style.css">
    <link rel="shortcut icon" href="/public/assets/img/favicon.svg" type="image/svg+xml">
</head>

<body>
    <input type="checkbox" id="cart-toggle" class="toggle" aria-label="Abrir carrinho">
    <header class="header">
        <div class="wrap">
            <a href="/" class="logo">Livo <span>SHOP</span></a>
            <nav class="nav" id="nav" aria-label="Principal">
                <a href="/" class="on">Loja</a>
                <a href="/about">Sobre</a>
                <a href="/contact">Contacto</a>
            </nav>
            <div class="actions">
                <button class="icon-btn" id="searchBtn" aria-label="Pesquisar"><i class="fa-solid fa-magnifying-glass"></i></button>
                <label for="cart-toggle" class="cart-btn">Carrinho <b class="numberTotal">0</b></label>
                <button class="icon-btn burger" id="burger" aria-label="Menu"><i class="fa-solid fa-bars"></i></button>
            </div>
        </div>
        <div class="search" id="search">
            <div class="wrap">
                <label><i class="fa-solid fa-magnifying-glass"></i>
                    <input id="searchInput" type="search" placeholder="Pesquisar t-shirt (ex: Laravel)" autocomplete="off">
                </label>
            </div>
        </div>
    </header>