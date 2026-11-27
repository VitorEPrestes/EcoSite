# Integração do blog: sistema interno → site

Documento de alinhamento entre duas frentes:

- **Sistema interno (Codex):** tela onde a equipe escreve e publica os textos.
- **Site (Claude):** páginas públicas, modelo visual, SEO e geração das páginas.

Status: **lado do site entregue em 2026-11-27** (gerador, modelo, migração e testes). Falta o lado do sistema — ver **seção 8, instruções para o Codex**.

> Este repositório é público. Não escrever aqui senhas, chaves, endereços internos do servidor nem caminhos absolutos do sistema. Os caminhos abaixo são sempre relativos à raiz do site.

---

## 1. Como o blog é hoje

O site é estático (HTML, CSS e JS), com URLs em formato de pasta.

| O quê | Caminho | Endereço público |
|---|---|---|
| Lista de textos | `blog/index.html` | `/blog/` |
| Um texto | `blog/<slug>/index.html` | `/blog/<slug>/` |
| Imagens | `assets/images/` | `/assets/images/...` |
| Mapa para o Google | `sitemap.xml` | `/sitemap.xml` |
| Cartões da página inicial | `blog/destaques.json` | lido pelo `js/main.js` |

Textos atuais: `matas-ciliares`, `coleta-seletiva`, `mudas-nativas`.
A pasta `blog/posts/` guarda apenas páginas de redirecionamento dos endereços antigos (`.html`). Não deve receber textos novos.

Endereço principal do site: `https://ecotecsolucoes.com.br` (sem `www`). Todo `canonical`, `og:url` e `og:image` usa esse endereço completo.

### Categorias (fixas)

| Valor (`categoria`) | Nome exibido |
|---|---|
| `reflorestamento` | Reflorestamento |
| `noticias` | Notícias |
| `dicas` | Dicas Ambientais |

O filtro da página `/blog/` usa esses três valores. Categoria nova exige mudança no site; o sistema não deve criar categorias por conta própria.

---

## 2. Dados de um texto

O sistema envia ao site **somente dados**, nunca HTML pronto. Quem monta a página é o site, a partir de um modelo único. Assim o visual fica em um só lugar e não há cópia do site dentro do sistema.

| Campo | Tipo | Regra |
|---|---|---|
| `slug` | texto | Minúsculas, números e hífen (`^[a-z0-9]+(-[a-z0-9]+)*$`), até 60 caracteres. Único. Não pode mudar depois de publicado. Proibidos: `posts`, `index`, `miniaturas`, nomes começando com `_`. |
| `titulo` | texto | 10 a 110 caracteres. |
| `resumo` | texto | 60 a 200 caracteres. Aparece no cartão da lista. |
| `descricao` | texto | 60 a 160 caracteres. Vai na meta description (Google). Se vazio, usa o `resumo`. |
| `categoria` | texto | Um dos três valores da tabela acima. |
| `data` | texto | `AAAA-MM-DD`. O site converte para "15 de abril de 2025". |
| `autor` | texto | Nome para exibição. Padrão: `Equipe Ecotec`. Não enviar e-mail nem login. |
| `imagem` | caminho | Sempre exatamente `assets/images/blog/<slug>.jpg` (o gerador recusa outro valor), JPG 16:9, 1600×900. |
| `imagem_alt` | texto | Obrigatório. Descreve a imagem em uma frase. |
| `corpo` | texto simples | Formato na seção 3. |
| `publicado` | verdadeiro/falso | Falso = rascunho: o texto existe só no sistema e não aparece no site nem no `sitemap.xml`. Despublicar um texto já publicado também usa falso. |

### Onde ficam os dados

```
blog/_dados/posts.json        índice: lista de textos, do mais novo ao mais antigo
blog/_dados/<slug>.json       um arquivo por texto, com todos os campos acima
assets/images/blog/<slug>.jpg imagem de capa já redimensionada
```

`posts.json` repete apenas `slug`, `titulo`, `resumo`, `categoria`, `data`, `imagem`, `imagem_alt` e `publicado`. É o que o site usa para montar a lista e os "Continue lendo".

---

## 3. Formato do `corpo`

Texto simples, sem HTML. O site escapa tudo.

- Linha em branco separa parágrafos.
- `## Título` vira subtítulo (`h2`).
- Linhas começando com `- ` viram lista.
- `**palavra**` vira negrito.
- `[texto do link](https://endereco)` vira link. Só `https://` ou caminho do próprio site começando com `/`. Links externos abrem em nova aba com `rel="noopener nofollow"`. Qualquer outro esquema (`javascript:`, `data:` etc.) é exibido como texto comum.
- Linha sozinha `![descrição da imagem](assets/images/blog/<slug>--<n>.jpg)` vira imagem no meio do texto, com a descrição como `alt` (obrigatória). Só caminhos dentro de `assets/images/blog/` do próprio texto; nunca endereço externo.
- Nada além disso: sem HTML. O site escapa tudo e só então aplica essas marcações.

Exemplo:

```
Primeiro parágrafo do texto.

## O que ela faz na prática

Outro parágrafo.

- primeiro item
- segundo item

Veja **mais detalhes** na [página de serviços](/#servicos).

![Mudas nativas no viveiro](assets/images/blog/meu-texto--1.jpg)
```

### Imagens do meio do texto

O sistema trata cada uma como a capa (reprocessa, remove metadados), mas com largura máxima de 1600 px mantendo a proporção, e grava em `assets/images/blog/<slug>--<n>.jpg` (`n` = 1 a 10, **dois hífens**). Limite: 10 imagens por texto.

> Mudança de 2026-11-27: eram `<slug>-<n>.jpg`, mas isso colide (a imagem 1 do texto `dicas` teria o mesmo nome da capa do texto `dicas-1`). Como o slug nunca tem dois hífens seguidos, `--` elimina a colisão.

Outras regras do corpo que o gerador aplica: uma quebra de linha simples dentro do parágrafo vira quebra de linha (`<br>`); `### ` e outras marcações não listadas aparecem como texto.

---

## 4. Quem faz o quê

### Sistema interno (Codex)

1. Tela de cadastro e edição com os campos da seção 2 e a lista de textos.
2. Validação no servidor, repetindo todas as regras acima (a validação do navegador não basta).
3. Envio da imagem: aceitar JPG, PNG ou WebP até 8 MB; converter para JPG, 1600×900, com corte centralizado; remover metadados (EXIF/GPS); salvar em `assets/images/blog/<slug>.jpg`.
4. Gravar `blog/_dados/<slug>.json` e atualizar `blog/_dados/posts.json` (escrita por arquivo temporário e troca, para nunca deixar arquivo pela metade).
5. Logo depois de gravar, rodar o gerador do site (ver seção 6, decisão 2).
6. Registro de quem publicou, editou ou despublicou, e quando, dentro do sistema.
7. Permissão de publicar restrita a usuários autorizados.

### Site (Claude)

1. Modelo das páginas (lista e texto), igual ao visual atual.
2. Gerador que lê `blog/_dados/` e produz `blog/index.html`, `blog/<slug>/index.html` e o `sitemap.xml`, com `canonical`, `og:*`, "Continue lendo" e escape de todo o conteúdo.
3. Migração dos 3 textos atuais para o novo formato de dados, com URLs idênticas.
4. Página 404 e redirecionamentos continuam como estão.
5. Testes: página gerada, XML válido, nenhum erro de JavaScript, celular e computador.

---

## 5. Permissões e segurança

- O sistema grava **somente** em `blog/_dados/` e `assets/images/blog/`; quem grava `blog/index.html`, `blog/<slug>/` e `sitemap.xml` é o gerador. Nada mais do site fica gravável.
- O contrário também vale: o site público nunca lê nada do sistema interno e nenhuma página gerada cita o endereço do sistema.
- `blog/_dados/` e `_gerador/` não devem ser servidos ao público (regra de negar no Nginx para qualquer caminho começando com `/blog/_` ou `/_gerador`). Os arquivos de dados existem para o gerador, não para visitantes.
- `slug` validado como na seção 2 antes de montar qualquer caminho. Nunca aceitar `/`, `..`, nem nome vindo do usuário em caminho de arquivo sem essa validação.
- O gerador escapa título, resumo, autor, alt e corpo. O sistema não envia HTML e o site não confia em HTML recebido.
- Imagem sempre reprocessada pelo sistema; nunca gravar o arquivo enviado como veio.
- Despublicar remove a pasta `blog/<slug>/` e tira o texto da lista e do `sitemap.xml`. O endereço passa a mostrar a página 404.

---

## 6. Decisões (fechadas em 2026-11-09)

**1. Conteúdo do blog fica só na VPS.**
O GitHub guarda o modelo, o gerador e o resto do site. `blog/index.html`, `blog/<slug>/`, `blog/_dados/`, `assets/images/blog/` e `sitemap.xml` passam a ser gerados na VPS e ficam no `.gitignore`. Os 3 textos atuais são migrados para `blog/_dados/` uma única vez. O backup da VPS precisa incluir essas pastas.

**2. Publicação na hora.**
O gerador é um script PHP do site (`_gerador/gerar-blog.php`). O sistema chama logo depois de gravar os dados, pela linha de comando do PHP (`php _gerador/gerar-blog.php`), sem passar pela internet. Ele regenera a lista, todas as páginas de texto e o `sitemap.xml`, e remove pastas de textos que não estão mais publicados. Saída: código 0 em sucesso; diferente de 0 com a mensagem de erro, que o sistema deve mostrar a quem publicou. A pasta `_gerador/` também fica bloqueada ao público no Nginx.

**3. Rascunho e publicado, sem segundo revisor.**
Quem tem permissão salva como rascunho (`publicado: false`) e publica quando quiser.

**4. Corpo com negrito, links e imagens no meio.**
Formato ampliado na seção 3, sempre sem HTML.

---

## 7. Roteiro de implantação

1. ~~Isolas fecha as decisões da seção 6.~~ Feito.
2. ~~Claude entrega modelo, gerador e migração dos 3 textos, com testes.~~ Feito em 2026-11-27 (seção 8).
3. Codex entrega a tela e a gravação conforme as seções 2, 3 e 5.
4. Teste combinado em ambiente de teste: criar, editar, trocar a imagem, despublicar, tentar slug inválido, tentar HTML no corpo, tentar link `javascript:`.
5. Publicação definitiva e conferência no Google Search Console.

---

## 8. Lado do site: entregue (2026-11-27)

Arquivos no repositório:

| Arquivo | Para quê |
|---|---|
| `_gerador/gerar-blog.php` | O gerador (só roda pela linha de comando; pela web devolve 404). |
| `_gerador/blog.php` | Validação, formato do corpo e modelos das páginas. |
| `_gerador/config.php` | Endereço do site, categorias e páginas fixas do `sitemap.xml`. |
| `_gerador/modelos/` | Menu, rodapé e ícones, copiados do visual atual. |
| `_gerador/semente/` | Os 3 textos atuais em formato de dados, para a migração. |
| `_gerador/testes/testar.php` | 52 verificações (formato, HTML malicioso, links `javascript:`, slug inválido, rascunho, despublicar). |

O que o gerador faz em cada execução:

1. Na primeira vez, se `blog/_dados/` não existir, copia os 3 textos de `_gerador/semente/` (migração única, mesmas URLs).
2. Lê **todos** os `blog/_dados/<slug>.json`. Esses arquivos são a fonte da verdade. `posts.json` é só conferido: se estiver desatualizado, sai um `aviso:` na saída, mas não bloqueia.
3. Valida todos os textos publicados. **Se um estiver inválido, nada é gravado** e a mensagem diz qual texto e qual campo.
4. Cria versões WebP leves da capa em `assets/images/blog/miniaturas/` (se o PHP tiver GD com WebP; sem isso, usa o JPG).
5. Grava `blog/index.html`, `blog/<slug>/index.html`, `blog/destaques.json` e `sitemap.xml`; remove pastas e miniaturas de textos despublicados.
6. Saída: código 0 e um relatório (`ok: N texto(s) publicado(s)...`); em erro, código 1 e a mensagem em stderr.

Os cartões de blog da página inicial são trocados pelos 3 textos mais novos via `blog/destaques.json`. A página inicial em si não é alterada pelo gerador, para o `git pull` nunca encontrar arquivo modificado na VPS.

### Instruções para o Codex

**A. Primeira instalação na VPS** (uma vez só, logo depois do `git pull` que trouxe o gerador):

Esse pull apaga do Git `blog/index.html`, as pastas dos 3 textos e o `sitemap.xml` (agora são gerados). Até o gerador rodar, `/blog/` e `/sitemap.xml` ficam fora do ar; por isso os passos abaixo devem ser feitos em sequência, logo após o pull.

A raiz do site **não** precisa (e não deve) ficar gravável pelo PHP. Como o pull apaga o `sitemap.xml` e o PHP não pode criar arquivo na raiz, o arquivo precisa ser recriado vazio, já pertencendo ao usuário do PHP. Trocar `www-data` pelo usuário real do Apache/PHP do sistema:

```
cd <raiz do site>
sudo chgrp www-data blog && sudo chmod 2775 blog
sudo install -d -o www-data -g www-data -m 2775 assets/images/blog
sudo install -o www-data -g www-data -m 664 /dev/null sitemap.xml
sudo -u www-data /usr/bin/php _gerador/gerar-blog.php
```

Resultado esperado: `ok: 3 texto(s) publicado(s), 6 arquivo(s) alterado(s).` Esse roteiro foi simulado com a raiz pertencendo a outro usuário e sem escrita para o PHP.

Garantias do gerador para essa etapa:

- Confere a permissão de todos os destinos antes de gravar; se faltar alguma, para com código 1 dizendo qual pasta ou arquivo, e **nenhuma página é alterada**.
- A migração dos 3 textos acontece enquanto `blog/_dados/` não tiver nenhum texto e ainda não tiver o marcador `blog/_dados/.migrado`. Se for interrompida, a próxima execução completa o que faltou. Depois de concluída, excluir todos os textos não traz os originais de volta.
- A trava de execução fica em `blog/.gerador.lock` (não em `/tmp`, que costuma ser privado para o Apache).

Os `git pull` seguintes não mexem nesses arquivos: todos estão no `.gitignore`. Mas atenção: se a pasta `blog/` for apagada e recriada por alguém, a permissão do passo `chgrp/chmod` precisa ser refeita.

Depois:

1. Nginx: negar `^/blog/_`, `^/_gerador` e arquivos ocultos (`/\.`), além do que já existe. Conferir que `https://ecotecsolucoes.com.br/blog/_dados/posts.json` e `/_gerador/config.php` dão 403/404.
2. Rodar os testes: `php _gerador/testes/testar.php` deve terminar com `0 falha(s)`.
3. Incluir `blog/_dados/` e `assets/images/blog/` no backup da VPS.

**B. Tela do sistema** (seções 2 a 5): gravar os arquivos de dados e a imagem e, logo depois, chamar o gerador e mostrar o resultado a quem publicou:

```php
$cmd = ['/usr/bin/php', $raizDoSite . '/_gerador/gerar-blog.php']; // caminho do PHP de linha de comando; não usar PHP_BINARY (no Apache ele não é o CLI)
$proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $raizDoSite);
$saida = stream_get_contents($pipes[1]);
$erro  = stream_get_contents($pipes[2]);
$codigo = proc_close($proc);
// $codigo === 0  -> publicado. Mostrar "Publicado".
// $codigo !== 0  -> mostrar $erro a quem publicou (já vem em português, diz o campo errado).
```

Detalhes que a tela precisa respeitar:

- Validar no servidor as mesmas regras da seção 2 antes de gravar (o gerador valida de novo, mas o erro fica mais claro na tela).
- Imagens do meio do texto: `<slug>--<n>.jpg` (dois hífens). A tela pode oferecer um botão "inserir imagem" que envia o arquivo e coloca a linha `![descrição](assets/images/blog/<slug>--<n>.jpg)` no corpo.
- Excluir um texto de vez: apagar `blog/_dados/<slug>.json`, a capa e as imagens `--n`, tirar do `posts.json` e rodar o gerador (ele remove a página).
- Rascunho (`publicado: false`) só precisa de slug válido; o resto é validado quando publicar.
- Trocar uma imagem: gravar o arquivo novo no mesmo caminho (por arquivo temporário e troca) e rodar o gerador. As páginas usam `?v=<data do arquivo>`, então o navegador busca a imagem nova.
- Gravação concorrente: o gerador já roda um por vez, mas a gravação dos JSONs + chamada do gerador deve ficar sob uma trava do próprio sistema, para dois editores não sobrescreverem o `posts.json` um do outro.
- A primeira execução cria as miniaturas WebP e pode levar alguns segundos; as seguintes só refazem o que mudou.

**C. Depois de cada `git pull` do site** que mexa em `_gerador/`, `css/` ou `js/`: rodar o gerador de novo, para as páginas do blog pegarem o modelo e a versão nova do CSS/JS.
