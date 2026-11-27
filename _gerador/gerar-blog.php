<?php
/*
 * Gerador do blog da Ecotec.
 *
 * Uso (na raiz do site, pela linha de comando):
 *     php _gerador/gerar-blog.php
 *
 * Lê blog/_dados/ e gera blog/index.html, blog/<slug>/index.html,
 * blog/destaques.json e sitemap.xml. Remove páginas de textos despublicados.
 *
 * Saída: código 0 em sucesso; 1 com a mensagem de erro em stderr.
 * Em erro, nenhum arquivo é alterado (tudo é montado antes de gravar).
 *
 * Enquanto blog/_dados/ não tiver nenhum texto, migra os 3 textos
 * originais de _gerador/semente/ (também refaz uma migração interrompida).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/blog.php';

$raiz = dirname(__DIR__);
$cfg = require __DIR__ . '/config.php';

// Uma execução por vez (o sistema pode publicar dois textos quase juntos).
// A trava fica em blog/ e não em /tmp: o Apache costuma ter /tmp privado,
// e a trava precisa ser a mesma para o sistema e para a linha de comando.
$trava = @fopen("$raiz/blog/.gerador.lock", 'c');
if (!$trava || !flock($trava, LOCK_EX)) {
    fwrite(STDERR, "Sem permissão para gravar em blog/. Ver BLOG-INTEGRACAO.md, seção 8, preparação da primeira instalação.\n");
    exit(1);
}

try {
    $relatorio = [];
    if (precisa_migrar($raiz)) {
        $relatorio = migrar_semente($raiz);
    }
    $relatorio = array_merge($relatorio, gerar_blog($raiz, $cfg));
    foreach ($relatorio as $linha) {
        echo $linha, "\n";
    }
    exit(0);
} catch (Throwable $e) {
    $msg = $e instanceof ErroBlog ? $e->getMessage() : 'Erro inesperado no gerador: ' . $e->getMessage();
    fwrite(STDERR, $msg . "\n");
    exit(1);
} finally {
    flock($trava, LOCK_UN);
}
