<?php
/*
 * Configuração do gerador do blog.
 * Este arquivo está no repositório público: não colocar senhas, chaves,
 * endereços internos do servidor nem caminhos absolutos.
 */

return [
    // Endereço público do site, sem barra no fim.
    'site_url' => 'https://ecotecsolucoes.com.br',

    // Categorias fixas: valor => nome exibido. Categoria nova exige mudança aqui
    // e um botão de filtro novo (o gerador cria os botões a partir desta lista).
    'categorias' => [
        'reflorestamento' => 'Reflorestamento',
        'noticias'        => 'Notícias',
        'dicas'           => 'Dicas Ambientais',
    ],

    'autor_padrao' => 'Equipe Ecotec',

    // Quantos textos aparecem em "Continue lendo" e nos destaques da página inicial.
    'relacionados' => 3,
    'destaques'    => 3,

    // Páginas fixas do site para o sitemap.xml (o gerador acrescenta o blog).
    // Ao mudar uma dessas páginas, atualizar o lastmod aqui.
    'sitemap_fixas' => [
        ['/',                                              '2026-11-22', '1.0'],
        ['/servicos/conservacao-florestas-nativas/',       '2026-11-11', '0.9'],
        ['/servicos/plantio-mudas-restauracao/',           '2026-11-11', '0.9'],
        ['/servicos/paisagismo-jardinagem/',               '2026-11-11', '0.9'],
        ['/servicos/rocagem-limpeza-areas/',               '2026-11-11', '0.9'],
        ['/servicos/controle-de-pragas/',                  '2026-11-11', '0.9'],
        ['/servicos/banheiros-quimicos/',                  '2026-11-11', '0.9'],
        ['/servicos/sistemas-agroflorestais/',             '2026-11-22', '0.9'],
        ['/servicos/educacao-ambiental/',                  '2026-11-22', '0.9'],
        ['/servicos/cercamento-isolamento-areas/',         '2026-11-22', '0.9'],
        ['/trabalhe-conosco/',                             '2026-11-11', '0.8'],
        ['/trabalhe-conosco/estagio/',                     '2026-11-11', '0.6'],
    ],
];
