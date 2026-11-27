<?php
/*
 * Funções do gerador do blog. Quem executa é gerar-blog.php.
 * Contrato com o sistema interno: BLOG-INTEGRACAO.md (raiz do site).
 *
 * Regras de segurança seguidas aqui:
 *  - todo texto vindo dos dados é escapado antes de ir para o HTML;
 *  - o corpo aceita só as marcações da seção 3 do contrato, aplicadas depois do escape;
 *  - nenhum caminho de arquivo é montado sem validar o slug;
 *  - nada é gravado se algum texto publicado estiver inválido.
 */

declare(strict_types=1);

final class ErroBlog extends RuntimeException {}

const SLUG_RE = '/^[a-z0-9]+(-[a-z0-9]+)*$/';
const SLUGS_PROIBIDOS = ['posts', 'index', 'miniaturas'];
const MESES = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho',
               'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
const MAX_IMAGENS_CORPO = 10;

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
}

function slug_valido(mixed $slug): bool
{
    return is_string($slug)
        && strlen($slug) <= 60
        && preg_match(SLUG_RE, $slug) === 1
        && !in_array($slug, SLUGS_PROIBIDOS, true);
}

function data_extenso(string $data): string
{
    [$a, $m, $d] = array_map('intval', explode('-', $data));
    return $d . ' de ' . MESES[$m - 1] . ' de ' . $a;
}

/* ------------------------------------------------------------------ */
/* Leitura e validação dos dados                                       */
/* ------------------------------------------------------------------ */

/**
 * Lê blog/_dados/<slug>.json de todos os textos.
 * Fonte da verdade: um arquivo por texto. posts.json é conferido só para avisar.
 * @return array{publicados: array<int,array>, avisos: string[]}
 */
function carregar_posts(string $raiz, array $cfg): array
{
    $dir = $raiz . '/blog/_dados';
    if (!is_dir($dir)) {
        throw new ErroBlog('Pasta blog/_dados não encontrada.');
    }

    $avisos = [];
    $publicados = [];
    $todos = [];

    foreach (scandir($dir) as $arq) {
        if (!str_ends_with($arq, '.json') || $arq === 'posts.json') {
            continue;
        }
        $slug = substr($arq, 0, -5);
        if (!slug_valido($slug)) {
            $avisos[] = "Arquivo ignorado (nome inválido): blog/_dados/$arq";
            continue;
        }
        $json = file_get_contents("$dir/$arq");
        $p = json_decode($json === false ? '' : $json, true);
        if (!is_array($p)) {
            throw new ErroBlog("blog/_dados/$arq não é um JSON válido.");
        }
        if (($p['slug'] ?? null) !== $slug) {
            throw new ErroBlog("blog/_dados/$arq: o campo slug deve ser \"$slug\".");
        }
        $todos[$slug] = (bool)($p['publicado'] ?? false);
        if (($p['publicado'] ?? false) !== true) {
            continue; // rascunho ou despublicado: não aparece no site
        }
        $p['_atualizado'] = gmdate('Y-m-d', (int)filemtime("$dir/$arq"));
        $publicados[] = validar_post($p, $raiz, $cfg);
    }

    // Conferência do índice mantido pelo sistema (só avisa, não bloqueia).
    $indice = @file_get_contents("$dir/posts.json");
    if ($indice !== false) {
        $lista = json_decode($indice, true);
        if (!is_array($lista)) {
            $avisos[] = 'blog/_dados/posts.json não é um JSON válido.';
        } else {
            $noIndice = [];
            foreach ($lista as $item) {
                if (is_array($item) && is_string($item['slug'] ?? null)) {
                    $noIndice[$item['slug']] = (bool)($item['publicado'] ?? false);
                }
            }
            foreach ($todos as $s => $pub) {
                if (!array_key_exists($s, $noIndice)) {
                    $avisos[] = "posts.json não lista \"$s\".";
                } elseif ($noIndice[$s] !== $pub) {
                    $avisos[] = "posts.json diverge de $s.json no campo publicado.";
                }
            }
            foreach (array_keys($noIndice) as $s) {
                if (!array_key_exists($s, $todos)) {
                    $avisos[] = "posts.json lista \"$s\", mas blog/_dados/$s.json não existe.";
                }
            }
        }
    }

    usort($publicados, fn($a, $b) => [$b['data'], $a['slug']] <=> [$a['data'], $b['slug']]);

    return ['publicados' => $publicados, 'avisos' => $avisos];
}

function validar_post(array $p, string $raiz, array $cfg): array
{
    $slug = $p['slug'];
    $erros = [];

    $texto = function (string $campo, int $min, int $max, bool $obrig = true) use ($p, &$erros): ?string {
        $v = $p[$campo] ?? null;
        if ($v === null || $v === '') {
            if ($obrig) {
                $erros[] = "$campo é obrigatório";
            }
            return null;
        }
        if (!is_string($v)) {
            $erros[] = "$campo deve ser texto";
            return null;
        }
        $v = trim($v);
        if (!mb_check_encoding($v, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $v)) {
            $erros[] = "$campo tem caracteres inválidos";
            return null;
        }
        if (preg_match('/[\r\n]/', $v)) {
            $erros[] = "$campo não pode ter quebra de linha";
            return null;
        }
        $n = mb_strlen($v);
        if ($n < $min || $n > $max) {
            $erros[] = "$campo deve ter de $min a $max caracteres (tem $n)";
        }
        return $v;
    };

    $titulo    = $texto('titulo', 10, 110);
    $resumo    = $texto('resumo', 60, 200);
    $descricao = $texto('descricao', 60, 160, false) ?? $resumo;
    $autor     = $texto('autor', 2, 60, false) ?? $cfg['autor_padrao'];
    $alt       = $texto('imagem_alt', 5, 200);

    $categoria = $p['categoria'] ?? null;
    if (!is_string($categoria) || !isset($cfg['categorias'][$categoria])) {
        $erros[] = 'categoria deve ser uma de: ' . implode(', ', array_keys($cfg['categorias']));
    }

    $data = $p['data'] ?? null;
    if (!is_string($data) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $data, $m)
        || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        $erros[] = 'data deve estar no formato AAAA-MM-DD';
    }

    // A capa tem caminho fixo; o valor enviado precisa bater com ele.
    $imagem = "assets/images/blog/$slug.jpg";
    if (($p['imagem'] ?? null) !== $imagem) {
        $erros[] = "imagem deve ser \"$imagem\"";
    } else {
        $info = @getimagesize("$raiz/$imagem");
        if ($info === false || $info[2] !== IMAGETYPE_JPEG) {
            $erros[] = "imagem de capa não encontrada ou não é JPG ($imagem)";
        }
    }

    $corpo = $p['corpo'] ?? null;
    $corpoHtml = '';
    if (!is_string($corpo) || trim($corpo) === '') {
        $erros[] = 'corpo é obrigatório';
    } elseif (!mb_check_encoding($corpo, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $corpo)) {
        $erros[] = 'corpo tem caracteres inválidos';
    } else {
        try {
            $corpoHtml = corpo_para_html($corpo, $slug, $raiz);
        } catch (ErroBlog $e) {
            $erros[] = $e->getMessage();
        }
    }

    if ($erros) {
        throw new ErroBlog("Texto \"$slug\" não pode ser publicado: " . implode('; ', $erros) . '.');
    }

    return [
        'slug'       => $slug,
        'titulo'     => $titulo,
        'resumo'     => $resumo,
        'descricao'  => $descricao,
        'categoria'  => $categoria,
        'data'       => $data,
        'autor'      => $autor,
        'imagem'     => $imagem,
        'imagem_alt' => $alt,
        'corpo_html' => $corpoHtml,
        'atualizado' => max($data, $p['_atualizado']),
    ];
}

/* ------------------------------------------------------------------ */
/* Corpo: texto simples -> HTML (seção 3 do contrato)                  */
/* ------------------------------------------------------------------ */

function corpo_para_html(string $corpo, string $slug, string $raiz): string
{
    $linhas = preg_split('/\R/u', str_replace("\t", '    ', $corpo));
    $saida = [];
    $paragrafo = [];
    $lista = [];
    $imagens = 0;

    $fecharParagrafo = function () use (&$paragrafo, &$saida) {
        if ($paragrafo) {
            $saida[] = '<p>' . implode("<br>\n", array_map('inline_para_html', $paragrafo)) . '</p>';
            $paragrafo = [];
        }
    };
    $fecharLista = function () use (&$lista, &$saida) {
        if ($lista) {
            $itens = array_map(fn($i) => '  <li>' . inline_para_html($i) . '</li>', $lista);
            $saida[] = "<ul>\n" . implode("\n", $itens) . "\n</ul>";
            $lista = [];
        }
    };

    foreach ($linhas as $bruta) {
        $linha = trim($bruta);

        if ($linha === '') {
            $fecharParagrafo();
            $fecharLista();
            continue;
        }

        if (str_starts_with($linha, '## ')) {
            $fecharParagrafo();
            $fecharLista();
            $saida[] = '<h2>' . inline_para_html(trim(substr($linha, 3))) . '</h2>';
            continue;
        }

        if (str_starts_with($linha, '- ')) {
            $fecharParagrafo();
            $lista[] = trim(substr($linha, 2));
            continue;
        }

        if (preg_match('/^!\[(.*)\]\((.*)\)$/u', $linha, $m)) {
            $fecharParagrafo();
            $fecharLista();
            $alt = trim($m[1]);
            $src = trim($m[2]);
            $esperado = '/^assets\/images\/blog\/' . preg_quote($slug, '/') . '--([1-9]|10)\.jpg$/';
            if ($alt === '') {
                throw new ErroBlog('imagem no corpo sem descrição: ' . $src);
            }
            if (!preg_match($esperado, $src)) {
                throw new ErroBlog("imagem no corpo fora do padrão assets/images/blog/$slug--N.jpg: $src");
            }
            $info = @getimagesize("$raiz/$src");
            if ($info === false || $info[2] !== IMAGETYPE_JPEG) {
                throw new ErroBlog("imagem do corpo não encontrada ou não é JPG: $src");
            }
            if (++$imagens > MAX_IMAGENS_CORPO) {
                throw new ErroBlog('mais de ' . MAX_IMAGENS_CORPO . ' imagens no corpo');
            }
            $saida[] = '<figure class="post-figure"><img src="/' . h($src) . '?v=' . filemtime("$raiz/$src") . '" alt="' . h($alt)
                . '" width="' . $info[0] . '" height="' . $info[1] . '" loading="lazy" decoding="async"></figure>';
            continue;
        }

        // Linha de texto comum: continua o parágrafo (ou encerra a lista).
        $fecharLista();
        $paragrafo[] = $linha;
    }
    $fecharParagrafo();
    $fecharLista();

    return implode("\n\n", $saida);
}

/** Escapa a linha e só então aplica links e negrito. */
function inline_para_html(string $texto): string
{
    $links = [];

    // 1) Links [texto](endereço), antes do escape, trocados por marcadores.
    $texto = preg_replace_callback('/\[([^\[\]]+)\]\(([^()\s]+)\)/u', function ($m) use (&$links) {
        $url = $m[2];
        $interno = preg_match('#^/(?![/\\\\])#', $url) === 1;
        $externo = preg_match('#^https://[^\s/\\\\]+#i', $url) === 1;
        if (!$interno && !$externo) {
            return $m[0]; // esquema não permitido: fica como texto comum
        }
        $attrs = $externo ? ' target="_blank" rel="noopener nofollow"' : '';
        $links[] = '<a href="' . h($url) . '"' . $attrs . '>' . negrito(h($m[1])) . '</a>';
        return "\u{E000}" . (count($links) - 1) . "\u{E001}";
    }, $texto);

    // 2) Escape de todo o resto e negrito.
    $texto = negrito(h($texto));

    // 3) Devolve os links já montados.
    return preg_replace_callback('/\x{E000}(\d+)\x{E001}/u', fn($m) => $links[(int)$m[1]], $texto);
}

function negrito(string $escapado): string
{
    return preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/u', '<strong>$1</strong>', $escapado);
}

/* ------------------------------------------------------------------ */
/* Imagens derivadas (WebP 1600 e 800) para as páginas ficarem leves    */
/* ------------------------------------------------------------------ */

/**
 * Cria assets/images/blog/miniaturas/<slug>.webp e <slug>-800.webp a partir do JPG.
 * Se o PHP não tiver suporte a WebP, as páginas usam o JPG original.
 * @return array{grande: string, pequena: ?string}  caminhos públicos (com / no início)
 */
function variantes_capa(string $raiz, array $p, bool &$gravou): array
{
    $jpg = "$raiz/{$p['imagem']}";
    // ?v= muda quando a imagem é trocada, para o navegador não mostrar a antiga do cache.
    $v = '?v=' . filemtime($jpg);
    $padrao = ['grande' => '/' . $p['imagem'] . $v, 'pequena' => null];
    if (!function_exists('imagewebp') || !function_exists('imagecreatefromjpeg')) {
        return $padrao;
    }

    $dir = "$raiz/assets/images/blog/miniaturas";
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return $padrao;
    }

    $saidas = ["$dir/{$p['slug']}.webp" => 1600, "$dir/{$p['slug']}-800.webp" => 800];
    $origemMtime = filemtime($jpg);
    $img = null;
    foreach ($saidas as $destino => $largura) {
        if (is_file($destino) && filemtime($destino) >= $origemMtime) {
            continue;
        }
        $img ??= @imagecreatefromjpeg($jpg);
        if (!$img) {
            return $padrao;
        }
        $w = imagesx($img);
        $hh = imagesy($img);
        $nova = $w > $largura ? imagescale($img, $largura, (int)round($hh * $largura / $w), IMG_BICUBIC) : $img;
        $tmp = "$destino.tmp";
        $ok = imagewebp($nova, $tmp, 80);
        if ($nova !== $img) {
            imagedestroy($nova);
        }
        if (!$ok || !rename($tmp, $destino)) {
            @unlink($tmp);
            return $padrao;
        }
        $gravou = true;
    }

    return [
        'grande'  => "/assets/images/blog/miniaturas/{$p['slug']}.webp$v",
        'pequena' => "/assets/images/blog/miniaturas/{$p['slug']}-800.webp$v",
    ];
}

/** Remove miniaturas de textos que não estão mais publicados. */
function limpar_miniaturas(string $raiz, array $slugs): array
{
    $dir = "$raiz/assets/images/blog/miniaturas";
    $removidos = [];
    if (!is_dir($dir)) {
        return $removidos;
    }
    foreach (scandir($dir) as $arq) {
        if (!str_ends_with($arq, '.webp')) {
            continue;
        }
        $base = substr($arq, 0, -5);
        $usado = in_array($base, $slugs, true)
            || (str_ends_with($base, '-800') && in_array(substr($base, 0, -4), $slugs, true));
        if (!$usado) {
            @unlink("$dir/$arq");
            $removidos[] = $arq;
        }
    }
    return $removidos;
}

/* ------------------------------------------------------------------ */
/* Modelos HTML                                                        */
/* ------------------------------------------------------------------ */

final class Modelo
{
    public function __construct(
        private string $raiz,
        private array $cfg,
    ) {}

    private function parte(string $nome): string
    {
        $c = file_get_contents(__DIR__ . "/modelos/$nome");
        if ($c === false) {
            throw new ErroBlog("Modelo ausente: _gerador/modelos/$nome");
        }
        return rtrim($c, "\n");
    }

    public function icone(string $nome): string
    {
        return $this->parte("ico-$nome.svg.html");
    }

    /** Versão para cache do CSS/JS, igual em todas as páginas geradas. */
    private function versao(string $arq): string
    {
        $c = @file_get_contents("$this->raiz/$arq");
        return substr(md5($c === false ? '' : $c), 0, 8);
    }

    public function url(string $caminho): string
    {
        return $this->cfg['site_url'] . $caminho;
    }

    public function cabecalho(array $o): string
    {
        $css = $this->versao('css/style.css');
        $metaExtra = $o['meta_extra'] ?? '';
        $ld = $o['json_ld'] ?? '';
        return <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="description" content="{$o['descricao']}">
  <meta name="theme-color" content="#2D6A4F">
  <title>{$o['titulo']}</title>
  <link rel="canonical" href="{$o['url']}">
  <meta property="og:url" content="{$o['url']}">
  <meta property="og:type" content="{$o['og_type']}">
{$metaExtra}  <meta property="og:locale" content="pt_BR">
  <meta property="og:site_name" content="Ecotec Soluções Ambientais">
  <meta property="og:title" content="{$o['titulo']}">
  <meta property="og:description" content="{$o['descricao']}">
  <meta property="og:image" content="{$o['imagem']}">
  <meta property="og:image:width" content="1600">
  <meta property="og:image:height" content="900">
  <meta name="twitter:card" content="summary_large_image">
  <script>document.documentElement.classList.add('js')</script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@600;700;800&family=Open+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/style.css?v={$css}">
  <link rel="icon" type="image/png" sizes="32x32" href="/assets/images/logo/favicon-32.png">
  <link rel="icon" type="image/png" sizes="192x192" href="/assets/images/logo/icon-192.png">
  <link rel="apple-touch-icon" href="/assets/images/logo/apple-touch-icon.png">
{$ld}</head>
<body>

{$this->parte('topo.html')}

HTML;
    }

    public function rodape(): string
    {
        $js = $this->versao('js/main.js');
        $r = preg_replace('/main\.js\?v=[A-Za-z0-9]+/', "main.js?v=$js", $this->parte('rodape.html'));
        return "\n" . $r . "\n";
    }

    /** Cartão usado na lista, em "Continue lendo" (mesmo HTML do site atual). */
    public function cartao(array $p, array $img, bool $comCategoria): string
    {
        $src = $img['pequena'] ?? $img['grande'];
        $nomeCat = h($this->cfg['categorias'][$p['categoria']]);
        $dataCat = $comCategoria ? ' data-category="' . h($p['categoria']) . '"' : '';
        $seta = $this->icone('arrow-right');
        return <<<HTML
        <article class="blog-card fade-in"{$dataCat}>
          <div class="blog-card-image">
            <img src="{$src}" alt="{$this->a($p['imagem_alt'])}" loading="lazy" width="800" height="450">
          </div>
          <div class="blog-card-body">
            <span class="blog-card-category">{$nomeCat}</span>
            <h3>{$this->a($p['titulo'])}</h3>
            <p>{$this->a($p['resumo'])}</p>
            <a href="/blog/{$p['slug']}/" class="blog-card-link">Ler mais {$seta}</a>
          </div>
        </article>
HTML;
    }

    private function a(string $s): string
    {
        return h($s);
    }

    public function lista(array $posts, array $imgs): string
    {
        $desc = 'Notas da Ecotec Soluções Ambientais sobre restauração, coleta seletiva, mudas nativas e cuidados práticos com áreas verdes.';
        $cab = $this->cabecalho([
            'titulo'    => 'Notas do Campo | Ecotec Soluções Ambientais',
            'descricao' => h($desc),
            'url'       => $this->url('/blog/'),
            'og_type'   => 'website',
            'imagem'    => $posts ? h($this->url('/' . $posts[0]['imagem'])) : $this->url('/assets/images/servico1.jpg'),
        ]);

        $botoes = ['        <button type="button" class="blog-filter-btn active" data-filter="todos">Todos</button>'];
        foreach ($this->cfg['categorias'] as $valor => $nome) {
            $botoes[] = '        <button type="button" class="blog-filter-btn" data-filter="' . h($valor) . '">' . h($nome) . '</button>';
        }
        $botoes = implode("\n", $botoes);

        $cartoes = $posts
            ? implode("\n", array_map(fn($p) => $this->cartao($p, $imgs[$p['slug']], true), $posts))
            : '        <p class="blog-empty">Nenhum texto publicado ainda.</p>';

        return $cab . <<<HTML
  <header class="page-header">
    <div class="container">
      <ol class="breadcrumb"><li><a href="/">Início</a></li><li>Blog</li></ol>
      <h1>Notas do campo</h1>
      <p>Textos simples sobre restauração, resíduos e cuidado ambiental na prática</p>
    </div>
  </header>

  <main>
  <section class="blog-page">
    <div class="container">
      <div class="blog-filters" role="group" aria-label="Filtrar por categoria">
{$botoes}
      </div>
      <div class="blog-page-grid">
{$cartoes}
      </div>
    </div>
  </section>
  </main>

HTML . $this->rodape();
    }

    public function post(array $p, array $img, array $outros, array $imgs): string
    {
        $url = $this->url("/blog/{$p['slug']}/");
        $nomeCat = $this->cfg['categorias'][$p['categoria']];
        $imgAbs = $this->url('/' . $p['imagem']) . '?v=' . filemtime("$this->raiz/{$p['imagem']}");

        $ld = [
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'BlogPosting',
                    'headline' => $p['titulo'],
                    'description' => $p['descricao'],
                    'image' => $imgAbs,
                    'datePublished' => $p['data'],
                    'dateModified' => $p['atualizado'],
                    'inLanguage' => 'pt-BR',
                    'articleSection' => $nomeCat,
                    'mainEntityOfPage' => $url,
                    'author' => ['@type' => 'Organization', 'name' => $p['autor'], 'url' => $this->url('/')],
                    'publisher' => [
                        '@type' => 'Organization',
                        'name' => 'Ecotec Soluções Ambientais',
                        'url' => $this->url('/'),
                        'logo' => ['@type' => 'ImageObject', 'url' => $this->url('/assets/images/logo/logotipo-1.png')],
                    ],
                ],
                [
                    '@type' => 'BreadcrumbList',
                    'itemListElement' => [
                        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Início', 'item' => $this->url('/')],
                        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Blog', 'item' => $this->url('/blog/')],
                        ['@type' => 'ListItem', 'position' => 3, 'name' => $p['titulo'], 'item' => $url],
                    ],
                ],
            ],
        ];
        // JSON_HEX_TAG impede que "</script>" no texto feche o bloco.
        $ldJson = json_encode($ld, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR);

        $cab = $this->cabecalho([
            'titulo'     => h($p['titulo']) . ' | Ecotec',
            'descricao'  => h($p['descricao']),
            'url'        => $url,
            'og_type'    => 'article',
            'meta_extra' => '  <meta property="article:published_time" content="' . $p['data'] . "\">\n",
            'imagem'     => h($imgAbs),
            'json_ld'    => "  <script type=\"application/ld+json\">\n$ldJson\n  </script>\n",
        ]);

        if ($img['pequena']) {
            $capa = '<img src="' . $img['grande'] . '" srcset="' . $img['pequena'] . ' 800w, ' . $img['grande']
                . ' 1600w" sizes="(max-width: 820px) 100vw, 780px" alt="' . h($p['imagem_alt'])
                . '" width="1600" height="900" fetchpriority="high">';
        } else {
            $capa = '<img src="' . $img['grande'] . '" alt="' . h($p['imagem_alt'])
                . '" width="1600" height="900" fetchpriority="high">';
        }

        $corpo = preg_replace('/^(?=.)/m', '      ', $p['corpo_html']);
        $cat = h($nomeCat);

        $relacionados = '';
        if ($outros) {
            $cartoes = implode("\n", array_map(fn($o) => $this->cartao($o, $imgs[$o['slug']], false), $outros));
            $relacionados = <<<HTML

  <section class="post-related">
    <div class="container">
      <div class="section-head">
        <span class="eyebrow">Continue lendo</span>
        <h2 class="section-title">Outras notas do campo</h2>
      </div>
      <div class="blog-page-grid">
{$cartoes}
      </div>
    </div>
  </section>
HTML;
        }

        return $cab . <<<HTML
  <header class="page-header">
    <div class="container">
      <ol class="breadcrumb"><li><a href="/">Início</a></li><li><a href="/blog/">Blog</a></li><li>{$cat}</li></ol>
      <p class="page-header-title">Notas do campo</p>
      <p>{$cat}</p>
    </div>
  </header>

  <main>
  <article class="post-content">
    <div class="post-meta">
      <span>{$this->icone('calendar')} <time datetime="{$p['data']}">{$this->a(data_extenso($p['data']))}</time></span>
      <span>{$this->icone('user')} {$this->a($p['autor'])}</span>
      <span>{$this->icone('tag')} {$cat}</span>
    </div>

    <h1>{$this->a($p['titulo'])}</h1>

    <div class="post-cover">
      {$capa}
    </div>

    <div class="post-body">
{$corpo}
    </div>

    <div class="post-cta">
      <div>
        <h3>Tem uma área para cuidar?</h3>
        <p>Conte o local e o tipo de serviço. A equipe responde com orientação objetiva.</p>
      </div>
      <a href="/#contato" class="btn btn-primary">Fale conosco {$this->icone('arrow-right')}</a>
    </div>

    <a href="/blog/" class="post-back">{$this->icone('arrow-left')} Voltar ao blog</a>
  </article>
{$relacionados}
  </main>

HTML . $this->rodape();
    }

    public function sitemap(array $posts): string
    {
        $urls = [];
        $add = function (string $caminho, string $lastmod, string $prio) use (&$urls) {
            $urls[] = "  <url>\n    <loc>" . h($this->url($caminho)) . "</loc>\n    <lastmod>$lastmod</lastmod>\n"
                . "    <priority>$prio</priority>\n  </url>";
        };
        foreach ($this->cfg['sitemap_fixas'] as [$caminho, $lastmod, $prio]) {
            $add($caminho, $lastmod, $prio);
        }
        $ultimo = $posts ? max(array_column($posts, 'atualizado')) : '2026-11-09';
        $add('/blog/', $ultimo, '0.8');
        $porSlug = $posts;
        usort($porSlug, fn($a, $b) => strcmp($a['slug'], $b['slug']));
        foreach ($porSlug as $p) {
            $add("/blog/{$p['slug']}/", $p['atualizado'], '0.6');
        }
        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n"
            . implode("\n", $urls) . "\n</urlset>\n";
    }

    /** Dados públicos para os cartões da página inicial (lidos pelo js/main.js). */
    public function destaques(array $posts, array $imgs): string
    {
        $itens = [];
        foreach (array_slice($posts, 0, $this->cfg['destaques']) as $p) {
            $itens[] = [
                'url'           => "/blog/{$p['slug']}/",
                'titulo'        => $p['titulo'],
                'resumo'        => $p['resumo'],
                'categoria'     => $this->cfg['categorias'][$p['categoria']],
                'imagem'        => $imgs[$p['slug']]['pequena'] ?? $imgs[$p['slug']]['grande'],
                'imagem_alt'    => $p['imagem_alt'],
            ];
        }
        return json_encode($itens, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            | JSON_THROW_ON_ERROR) . "\n";
    }
}

/* ------------------------------------------------------------------ */
/* Gravação                                                            */
/* ------------------------------------------------------------------ */

/**
 * Grava sem deixar arquivo pela metade: escreve ao lado e troca.
 * Se a pasta não permitir criar arquivo (ex.: raiz do site), escreve por cima
 * do arquivo existente com trava — por isso sitemap.xml precisa existir e ser
 * gravável pelo usuário que roda o gerador.
 */
function gravar(string $caminho, string $conteudo): bool
{
    if (is_file($caminho) && file_get_contents($caminho) === $conteudo) {
        return false; // nada mudou
    }
    $dir = dirname($caminho);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        throw new ErroBlog("Não foi possível criar a pasta $dir.");
    }
    if (is_writable($dir)) {
        $tmp = $dir . '/.' . basename($caminho) . '.' . getmypid() . '.tmp';
        if (file_put_contents($tmp, $conteudo) === strlen($conteudo)) {
            @chmod($tmp, 0664);
            if (rename($tmp, $caminho)) {
                return true;
            }
        }
        @unlink($tmp);
    }
    if (is_file($caminho) && is_writable($caminho)) {
        $f = fopen($caminho, 'c');
        if ($f && flock($f, LOCK_EX)) {
            ftruncate($f, 0);
            fwrite($f, $conteudo);
            fflush($f);
            flock($f, LOCK_UN);
            fclose($f);
            return true;
        }
    }
    throw new ErroBlog('Sem permissão para gravar ' . $caminho . '. Se for o sitemap.xml na primeira instalação, '
        . 'ele precisa existir e pertencer ao usuário do PHP (BLOG-INTEGRACAO.md, seção 8).');
}

/** O arquivo pode ser criado/trocado na pasta, ou sobrescrito no lugar. */
function pode_gravar(string $caminho): bool
{
    $dir = dirname($caminho);
    while (!is_dir($dir)) { // pasta ainda não existe: vale a permissão da primeira que existe
        $dir = dirname($dir);
    }
    return is_writable($dir) || (is_file($caminho) && is_writable($caminho));
}

/**
 * Remove blog/<pasta>/ de textos que não estão mais publicados.
 * Só apaga pastas com nome de slug que contenham apenas index.html,
 * para nunca tocar em blog/posts/, blog/_dados/ ou outra coisa.
 */
function remover_orfaos(string $raiz, array $slugs): array
{
    $removidos = [];
    foreach (scandir("$raiz/blog") as $nome) {
        $dir = "$raiz/blog/$nome";
        if (!is_dir($dir) || !slug_valido($nome) || in_array($nome, $slugs, true)) {
            continue;
        }
        $conteudo = array_values(array_diff(scandir($dir), ['.', '..']));
        if ($conteudo === ['index.html']) {
            unlink("$dir/index.html");
            rmdir($dir);
            $removidos[] = "blog/$nome/";
        }
    }
    return $removidos;
}

/**
 * Executa a geração completa. Monta tudo na memória antes de gravar o primeiro arquivo.
 * @return string[] linhas de relatório
 */
function gerar_blog(string $raiz, array $cfg): array
{
    $dados = carregar_posts($raiz, $cfg);
    $posts = $dados['publicados'];
    $rel = array_map(fn($a) => "aviso: $a", $dados['avisos']);

    $modelo = new Modelo($raiz, $cfg);
    $imgs = [];
    $imagensNovas = false;
    foreach ($posts as $p) {
        $imgs[$p['slug']] = variantes_capa($raiz, $p, $imagensNovas);
    }

    $arquivos = [];
    $arquivos['blog/index.html'] = $modelo->lista($posts, $imgs);
    foreach ($posts as $p) {
        $outros = array_values(array_filter($posts, fn($o) => $o['slug'] !== $p['slug']));
        $outros = array_slice($outros, 0, $cfg['relacionados']);
        $arquivos["blog/{$p['slug']}/index.html"] = $modelo->post($p, $imgs[$p['slug']], $outros, $imgs);
    }
    $arquivos['blog/destaques.json'] = $modelo->destaques($posts, $imgs);
    $arquivos['sitemap.xml'] = $modelo->sitemap($posts);

    // Confere permissão de todos os destinos antes de gravar o primeiro arquivo.
    foreach (array_keys($arquivos) as $caminho) {
        if (!pode_gravar("$raiz/$caminho")) {
            throw new ErroBlog("Sem permissão para gravar $caminho. Nada foi alterado. "
                . 'Se for o sitemap.xml na primeira instalação, ele precisa existir e pertencer ao usuário do PHP '
                . '(BLOG-INTEGRACAO.md, seção 8).');
        }
    }

    $alterados = 0;
    foreach ($arquivos as $caminho => $conteudo) {
        if (gravar("$raiz/$caminho", $conteudo)) {
            $alterados++;
            $rel[] = "gravado: $caminho";
        }
    }

    $slugs = array_column($posts, 'slug');
    foreach (remover_orfaos($raiz, $slugs) as $r) {
        $rel[] = "removido: $r";
    }
    foreach (limpar_miniaturas($raiz, $slugs) as $r) {
        $rel[] = "removida miniatura: $r";
    }
    if ($imagensNovas) {
        $rel[] = 'miniaturas WebP atualizadas';
    }

    $rel[] = sprintf('ok: %d texto(s) publicado(s), %d arquivo(s) alterado(s).', count($posts), $alterados);
    return $rel;
}

/** Verdadeiro se a migração nunca terminou e ainda não há nenhum texto em blog/_dados/. */
function precisa_migrar(string $raiz): bool
{
    $dir = "$raiz/blog/_dados";
    if (!is_dir($dir)) {
        return true;
    }
    if (is_file("$dir/.migrado")) {
        return false; // já migrou uma vez; blog vazio depois disso é intencional
    }
    foreach (scandir($dir) as $arq) {
        if (str_ends_with($arq, '.json') && $arq !== 'posts.json') {
            return false;
        }
    }
    return true;
}

/**
 * Primeira execução: copia os 3 textos originais de _gerador/semente/.
 * Confere as permissões antes de criar qualquer coisa e copia as imagens antes
 * dos dados, para que uma falha no meio nunca deixe a migração pela metade.
 */
function migrar_semente(string $raiz): array
{
    $origem = __DIR__ . '/semente';
    $destDados = "$raiz/blog/_dados";
    $destImg = "$raiz/assets/images/blog";

    foreach ([$destImg => 'assets/images/blog/', $destDados => 'blog/_dados/'] as $d => $nome) {
        $pai = dirname($d);
        if (is_dir($d) ? !is_writable($d) : !is_writable($pai)) {
            throw new ErroBlog("Sem permissão para gravar em $nome (primeira instalação). "
                . 'Ver BLOG-INTEGRACAO.md, seção 8.');
        }
    }

    $rel = [];
    foreach ([$destImg, $destDados] as $d) {
        if (!is_dir($d) && !@mkdir($d, 0775, true)) {
            throw new ErroBlog("Não foi possível criar $d.");
        }
    }
    foreach (glob("$origem/imagens/*.jpg") as $arq) {
        $dest = "$destImg/" . basename($arq);
        if (!is_file($dest)) {
            if (!copy($arq, $dest)) {
                throw new ErroBlog('Falha ao copiar ' . basename($arq) . '.');
            }
            $rel[] = 'migrado: assets/images/blog/' . basename($arq);
        }
    }
    // posts.json por último: os textos já estarão completos quando o índice aparecer.
    $arquivos = glob("$origem/dados/*.json");
    usort($arquivos, fn($a, $b) => (basename($a) === 'posts.json') <=> (basename($b) === 'posts.json'));
    foreach ($arquivos as $arq) {
        $dest = "$destDados/" . basename($arq);
        if (!is_file($dest)) {
            if (!copy($arq, $dest)) {
                throw new ErroBlog('Falha ao copiar ' . basename($arq) . '.');
            }
            $rel[] = 'migrado: blog/_dados/' . basename($arq);
        }
    }
    file_put_contents("$destDados/.migrado", gmdate('c') . "\n");
    return $rel;
}
