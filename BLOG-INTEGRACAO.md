# Integração do blog: sistema interno → site

Documento de alinhamento entre duas frentes:

- **Sistema interno (Codex):** tela onde a equipe escreve e publica os textos.
- **Site (Claude):** páginas públicas, modelo visual, SEO e geração das páginas.

Status: **proposta para revisão**. Os itens marcados como **Decisão em aberto** precisam ser fechados pelo Isolas antes de implementar.

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
| `slug` | texto | Minúsculas, números e hífen (`^[a-z0-9]+(-[a-z0-9]+)*$`), até 60 caracteres. Único. Não pode mudar depois de publicado. Proibidos: `posts`, `index`, nomes começando com `_`. |
| `titulo` | texto | 10 a 110 caracteres. |
| `resumo` | texto | 60 a 200 caracteres. Aparece no cartão da lista. |
| `descricao` | texto | 60 a 160 caracteres. Vai na meta description (Google). Se vazio, usa o `resumo`. |
| `categoria` | texto | Um dos três valores da tabela acima. |
| `data` | texto | `AAAA-MM-DD`. O site converte para "15 de abril de 2025". |
| `autor` | texto | Nome para exibição. Padrão: `Equipe Ecotec`. Não enviar e-mail nem login. |
| `imagem` | caminho | `assets/images/blog/<slug>.jpg`, proporção 16:9, 1600×900. |
| `imagem_alt` | texto | Obrigatório. Descreve a imagem em uma frase. |
| `corpo` | texto simples | Formato na seção 3. |
| `publicado` | verdadeiro/falso | Falso remove o texto do site e do `sitemap.xml`. |

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
- Nada além disso: sem HTML, sem imagens no meio do texto, sem links externos nesta primeira versão.

Exemplo:

```
Primeiro parágrafo do texto.

## O que ela faz na prática

Outro parágrafo.

- primeiro item
- segundo item
```

---

## 4. Quem faz o quê

### Sistema interno (Codex)

1. Tela de cadastro e edição com os campos da seção 2 e a lista de textos.
2. Validação no servidor, repetindo todas as regras acima (a validação do navegador não basta).
3. Envio da imagem: aceitar JPG, PNG ou WebP até 8 MB; converter para JPG, 1600×900, com corte centralizado; remover metadados (EXIF/GPS); salvar em `assets/images/blog/<slug>.jpg`.
4. Gravar `blog/_dados/<slug>.json` e atualizar `blog/_dados/posts.json` (escrita por arquivo temporário e troca, para nunca deixar arquivo pela metade).
5. Acionar a geração das páginas (ver **Decisão em aberto 2**).
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

- O sistema grava **somente** em: `blog/_dados/`, `blog/<slug>/`, `blog/index.html`, `assets/images/blog/` e `sitemap.xml`. Nada mais do site fica gravável.
- O contrário também vale: o site público nunca lê nada do sistema interno e nenhuma página gerada cita o endereço do sistema.
- `blog/_dados/` não deve ser servido ao público (regra de negar no Nginx para qualquer caminho começando com `/blog/_`). Os arquivos de dados existem para o gerador, não para visitantes.
- `slug` validado como na seção 2 antes de montar qualquer caminho. Nunca aceitar `/`, `..`, nem nome vindo do usuário em caminho de arquivo sem essa validação.
- O gerador escapa título, resumo, autor, alt e corpo. O sistema não envia HTML e o site não confia em HTML recebido.
- Imagem sempre reprocessada pelo sistema; nunca gravar o arquivo enviado como veio.
- Despublicar remove a pasta `blog/<slug>/` e tira o texto da lista e do `sitemap.xml`. O endereço passa a mostrar a página 404.

---

## 6. Decisões em aberto

**1. Git e arquivos gerados.**
Hoje o `blog/index.html` e os 3 textos estão no Git. Se o sistema regenerar esses arquivos na VPS, o próximo `git pull` pode dar conflito.
Sugestão: migrar os 3 textos para dados, deixar `blog/index.html`, `blog/<slug>/` e `blog/_dados/` fora do Git (`.gitignore`) e manter no Git o modelo e o gerador. O Git passa a guardar o código; o conteúdo do blog vive na VPS.
Cuidado: com isso o backup do conteúdo do blog precisa estar no backup da VPS.

**2. Como o sistema aciona o gerador.**
Sugestão: gerador em PHP (a VPS já roda PHP), chamado pelo sistema logo depois de gravar os dados. Alternativa: o sistema só grava os dados e um agendamento na VPS roda o gerador a cada poucos minutos. A primeira dá resultado imediato; a segunda isola mais o sistema do site.

**3. Quem revisa antes de publicar.**
Hoje a proposta é publicar direto. Alternativa: estado "rascunho" e "publicado", com revisão por um segundo usuário. O campo `publicado` já cobre o rascunho.

**4. Corpo em texto simples basta?**
Sem links externos, negrito ou imagens no meio do texto. Se isso fizer falta, ampliar o formato na seção 3 em vez de aceitar HTML.

---

## 7. Roteiro de implantação

1. Isolas fecha as decisões da seção 6.
2. Claude entrega modelo, gerador e migração dos 3 textos, com testes.
3. Codex entrega a tela e a gravação conforme as seções 2, 3 e 5.
4. Teste combinado em ambiente de teste: criar, editar, trocar a imagem, despublicar, tentar slug inválido, tentar HTML no corpo.
5. Publicação definitiva e conferência no Google Search Console.
