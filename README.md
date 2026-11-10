# Wallet PHP — Grupo Adriano Cobuccio

Aplicação do desafio técnico Fullstack PHP do Grupo Adriano Cobuccio. O objetivo final é uma carteira financeira com cadastro, autenticação, depósitos, transferências e reversões, conforme o documento fornecido em `../regras_desafio/desafio fullstack PHP.docx`.

A etapa 03 implementa cadastro com carteira inicial, login/logout por sessão e dashboard individual em Blade/Bootstrap. As migrations existentes foram preservadas. Depósitos, transferências e reversões permanecem somente documentados em [BUSINESS_RULES.md](docs/BUSINESS_RULES.md) e [ARCHITECTURE.md](docs/ARCHITECTURE.md), aguardando revisão antes da implementação financeira.

## Stack

- PHP 8.3, Laravel 12 e Blade.
- Laravel Sail oficial, com runtime `vendor/laravel/sail/runtimes/8.3`; nenhum Dockerfile próprio.
- MySQL 8.4, da família MySQL 8, conforme o serviço oficial do Sail.
- phpMyAdmin 5.2.3 oficial, somente no loopback local.
- Composer com autoload PSR-4 e dependências fixadas em `composer.lock`.
- PHPUnit e Laravel Pint com preset PSR-12 em `pint.json`.
- Bootstrap 5.3.8 instalado localmente, compilado com Vite e fixado em `package-lock.json`. Sem CDN, fontes externas ou biblioteca adicional de interface.

## Requisitos

- Windows com PowerShell, Docker Desktop iniciado e backend Linux/WSL2 habilitado.
- Docker Engine e Docker Compose disponíveis no terminal.
- Git para versionamento.
- PHP 8.3 e Composer locais apenas para instalar dependências no primeiro bootstrap, caso `vendor` ainda não exista. Habilitar as extensões exigidas pelo Composer, incluindo `curl`, `mbstring`, `openssl`, `xml`, `zip` e PDO.
- Acesso à internet para baixar pacotes e construir as imagens oficiais.
- Portas locais 8088, 3309 e 8089 livres.

Após o bootstrap, executar PHP, Artisan e npm dentro do Docker. O Sail já fornece Node e npm; não é necessário instalar Node no Windows. As páginas dependem da compilação inicial dos assets descrita abaixo.

## Inicialização no PowerShell

Abra o Docker Desktop e aguarde o engine iniciar. No diretório do projeto:

```powershell
Set-Location C:\Projetos\AdrianoCobuccio\wallet-php
docker info
docker compose version
```

Em uma cópia nova do repositório, instale as dependências e crie o ambiente somente se ainda não existir:

```powershell
composer install --no-scripts --no-interaction --prefer-dist
if (!(Test-Path -LiteralPath .env)) { Copy-Item -LiteralPath .env.example -Destination .env }
```

No `.env`, preencha `DB_PASSWORD` e `MYSQL_ROOT_PASSWORD` com senhas aleatórias e distintas, exclusivas deste ambiente de desenvolvimento. O ambiente preparado já possui essas senhas locais. Não as publique nem substitua o `.env` existente. Mantenha `APP_KEY` vazio somente até a geração descrita abaixo.

As variáveis principais são:

```dotenv
APP_URL=http://localhost:8088
COMPOSE_PROJECT_NAME=wallet-php
APP_PORT=8088
FORWARD_DB_PORT=3309
WWWUSER=1000
WWWGROUP=1000
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=wallet_challenge
DB_USERNAME=wallet_dev
```

Valide a configuração e inicie os serviços:

```powershell
docker compose config --quiet
docker compose up -d --build --wait --wait-timeout 180
docker compose exec -T --user sail laravel.test composer install --no-interaction --prefer-dist
```

Na primeira inicialização, gere a chave de aplicação se `APP_KEY` estiver vazio:

```powershell
docker compose exec -T --user sail laravel.test php artisan key:generate
```

Não regenere a chave de uma aplicação já em uso: dados criptografados com a chave anterior dependem dela. Nesta entrega, a chave já foi gerada no `.env` local.

Execute as migrations:

```powershell
docker compose exec -T --user sail laravel.test php artisan migrate
docker compose exec -T --user sail laravel.test php artisan migrate:status
```

Instale as dependências frontend pelo lock e compile os assets locais:

```powershell
docker compose exec -T --user sail laravel.test npm ci
docker compose exec -T --user sail laravel.test npm run build
```

Repita `npm run build` após alterar CSS ou JavaScript. O resultado em `public/build` não é versionado; `package.json` e `package-lock.json` são. Os assets compilados são servidos pela porta 8088 e não precisam de um servidor Vite ou de CDN durante a apresentação.

Acesse **http://localhost:8088**. A primeira construção pode demorar devido aos pacotes incluídos pelo runtime oficial do Sail. O Docker Compose aguarda o healthcheck do MySQL antes de iniciar a aplicação.

## Funcionalidades e criação de usuário

- Página inicial com acesso ao cadastro e ao login.
- Cadastro com nome, e-mail, senha e confirmação; usuário e carteira com saldo zero são gravados em uma única transação.
- Login por e-mail/senha, renovação da sessão, logout com invalidação da sessão e renovação do token CSRF.
- Dashboard autenticado com nome e saldo da própria carteira em reais; área de movimentações reservada para etapa futura.
- Validação em português, escaping do Blade, CSRF e limite de tentativas de login.

Para criar um usuário, abra **http://localhost:8088/register**, preencha os campos e confirme. A senha exige pelo menos 12 caracteres e no máximo 72 bytes, para respeitar o bcrypt sem truncamento; caracteres acentuados podem ocupar mais de um byte. O cadastro normaliza o e-mail para minúsculas, protege a senha com Hash do Laravel e inicia a sessão após o commit. Não há credenciais de demonstração nem seed necessário para acessar a aplicação.

Login em **http://localhost:8088/login**; após autenticar, o dashboard fica em **http://localhost:8088/dashboard**. Use o botão **Sair** para encerrar a sessão. Usuários antigos criados fora do fluxo e sem carteira recebem uma mensagem no dashboard; a consulta não cria carteira nem altera saldo.

| Método | Rota | Acesso |
| --- | --- | --- |
| GET | `/` | Público |
| GET / POST | `/register` | Visitante |
| GET / POST | `/login` | Visitante |
| GET | `/dashboard` | Autenticado |
| POST | `/logout` | Autenticado, com CSRF |

O login utiliza mensagem genérica para e-mail/senha incorretos. São permitidas cinco falhas por e-mail/IP e vinte por IP em 60 segundos. Um login válido limpa o contador do par e-mail/IP; o limite global do IP permanece até expirar. As chaves do cache usam hashes, sem e-mail/IP em texto claro.

## Serviços e persistência

| Serviço | Imagem ou runtime | Porta no host | Porta interna |
| --- | --- | --- | --- |
| `laravel.test` | Sail PHP 8.3 (`sail-8.3/app`) | `127.0.0.1:8088` | `80` |
| `mysql` | `mysql:8.4` | `127.0.0.1:3309` | `3306` |
| `phpmyadmin` | `phpmyadmin:5.2.3-apache` | `127.0.0.1:8089` | `80` |

O Laravel acessa `mysql:3306` na rede Docker `sail`, usando o banco `wallet_challenge` e o usuário `wallet_dev`. Clientes no Windows usam `127.0.0.1:3309`, com as credenciais do `.env`.

O volume nomeado `sail-mysql`, com nome efetivo `wallet-php_sail-mysql`, persiste `/var/lib/mysql`. O script oficial do Sail também cria o banco separado `testing` e concede acesso ao usuário de desenvolvimento para os testes.

As credenciais de inicialização do MySQL são aplicadas na criação de um volume vazio. Alterar apenas o `.env` não altera usuários e senhas de um volume já inicializado; investigar e planejar essa alteração preservando os dados.

Somente os três serviços acima são configurados. E-mails usam o driver `log`; sessões, cache e filas usam o banco conforme os padrões do Laravel. Nenhum worker é iniciado nesta etapa.

## phpMyAdmin

A imagem oficial está fixada em `phpmyadmin:5.2.3-apache`, versão estável identificada nas [publicações oficiais](https://www.phpmyadmin.net/downloads/). O serviço usa a rede `sail`, com `PMA_HOST=mysql` e `PMA_PORT=3306`, sem montar nem alterar o volume MySQL.

```powershell
docker compose config --quiet
docker compose up -d --no-deps --wait phpmyadmin
```

O MySQL deve estar ativo e saudável antes desse comando. Abra **http://localhost:8089** (ou `http://127.0.0.1:8089`) e faça login normal com `DB_USERNAME` e `DB_PASSWORD` do seu `.env` local. Selecione `wallet_challenge` para inspecionar as tabelas. Não usar root nem salvar credenciais no código. Não há `PMA_USER`, `PMA_PASSWORD` ou login automático.

A porta publica exclusivamente `127.0.0.1:8089:80`; não está disponível pela rede externa. A versão fixada do phpMyAdmin possui seu próprio runtime, separado do PHP 8.3 da aplicação. Não executar alterações ou exclusões manuais em dados financeiros para simular movimentações.

## Testes e formatação

O `phpunit.xml`, configurado pelo Sail, usa `APP_ENV=testing` e `DB_DATABASE=testing`, separado do banco de desenvolvimento. Antes da primeira execução dos testes de esquema, aplique migrations nesse banco sem reset:

```powershell
docker compose exec -T --user sail -e DB_DATABASE=testing laravel.test php artisan migrate --env=testing --no-interaction
```

Depois execute:

```powershell
docker compose exec -T --user sail laravel.test php artisan test
docker compose exec -T --user sail laravel.test php vendor/bin/pint --test
```

Os testes padrão verificam a asserção unitária e a página inicial. `WalletSchemaTest` valida a integridade relacional; `AuthenticationTest` cobre cadastro, rollback, sessão, CSRF, rate limiting e isolamento da carteira. Os testes de banco revertem suas fixtures por transação e recusam um banco diferente de `testing`; não usam `RefreshDatabase`, `migrate:fresh` ou reset. `BrazilianCurrencyTest` verifica centavos positivos/negativos e os limites de inteiro de 64 bits, sem float.

Os testes HTTP substituem a integração Vite por `withoutVite()` para não depender de assets gerados. A compilação é validada separadamente com `npm run build`. Um teste ativa explicitamente o middleware CSRF, dispensado pelo Laravel nos testes comuns. Movimentações financeiras e suas garantias concorrentes ainda não estão implementadas nem testadas.

Para aplicar PSR-12 ao código:

```powershell
docker compose exec -T --user sail laravel.test php vendor/bin/pint
```

## Comandos úteis

```powershell
# Estado e logs somente deste projeto
docker compose ps
docker compose logs --tail=100 laravel.test mysql

# Versões e conexão com o banco
docker compose exec -T --user sail laravel.test php -v
docker compose exec -T --user sail laravel.test php artisan --version
docker compose exec -T --user sail laravel.test php artisan db:show
docker compose exec -T mysql mysqld --version

# Verificações do Composer
docker compose exec -T --user sail laravel.test composer validate --strict
docker compose exec -T --user sail laravel.test composer check-platform-reqs

# Limpar cache de configuração
docker compose exec -T --user sail laravel.test php artisan config:clear

# Parar e retomar preservando o volume
docker compose stop
docker compose up -d --wait
```

No WSL2, a partir de `/mnt/c/Projetos/AdrianoCobuccio/wallet-php`, também é possível usar os atalhos oficiais:

```bash
./vendor/bin/sail up -d
./vendor/bin/sail artisan migrate
./vendor/bin/sail test
./vendor/bin/sail pint --test
./vendor/bin/sail stop
```

No PowerShell, use `docker compose` como documentado acima: o wrapper `vendor/bin/sail` exige um shell compatível com Bash. Não é necessário publicar arquivos do runtime nem criar Dockerfiles personalizados.

## Segurança e diretrizes

O `.gitignore` impede o versionamento de `.env`, variações locais, credenciais, `vendor`, `node_modules`, caches e arquivos temporários. O `.env.example` contém campos de senha e chave vazios. As portas publicadas estão restritas ao loopback, e a conta da aplicação é distinta de root. `APP_DEBUG=true` é exclusivo do desenvolvimento local.

As diretrizes de MVC com camada de serviços, SOLID, PSR-12, PSR-4, tipagem, comentários em português e testes financeiros constam em [AGENTS.md](AGENTS.md). A referência de segurança é o [OWASP Top 10:2025](https://owasp.org/Top10/2025/).

Não executar `docker compose down -v`, `migrate:fresh` ou excluir volumes sem autorização. Essas ações podem apagar dados persistidos.

Referência de instalação e seleção de runtime: [documentação oficial do Laravel Sail 12.x](https://laravel.com/docs/12.x/sail).

## Validação da infraestrutura em 10/11/2026

| Item | Resultado observado |
| --- | --- |
| Docker Engine / Compose | 29.9.3 / v5.7.0 |
| Serviços | `laravel.test` ativo e `mysql` ativo com healthcheck saudável |
| PHP no container | 8.3.36 |
| Laravel Framework | 12.72.0 |
| Laravel Sail | 1.69.0 |
| MySQL Server | 8.4.13 |
| Composer no container | 2.11.2 |
| PHPUnit / Pint | 11.5.57 / 1.32.1 |
| Conexão | `wallet_dev` conectado a `wallet_challenge` em `mysql:3306` |
| Persistência | Volume `wallet-php_sail-mysql`, driver `local` |
| Migrations | As três migrations padrão executadas no lote 1; nove tabelas presentes |
| Testes padrão | Dois testes aprovados, duas asserções, após a formatação |
| PSR-12 | `pint --test` aprovado em 26 arquivos |
| Composer | `validate --strict` e `check-platform-reqs` aprovados |
| HTTP pelo Windows | `/` e `/up` responderam 200 em `http://localhost:8088` |
| Gitignore | Exclusões conferidas com `git check-ignore`; `.env.example` permitido |

Foram criados o skeleton Laravel 12, `composer.lock`, a configuração oficial `compose.yaml`, `.env` local, `.env.example`, `.gitignore`, `pint.json`, `AGENTS.md` e este README. O Sail configurou `phpunit.xml` para usar o banco de testes separado. O Pint ajustou somente a formatação de `User.php` e das três migrations padrão para PSR-12.

O bootstrap usou o PHP/Composer local. O instalador do Sail publicou os arquivos, mas sua tentativa automática de shell encontrou incompatibilidade com PowerShell; a construção e a execução foram concluídas com Docker Compose. Depois da inicialização, as verificações PHP, Artisan, Composer, PHPUnit e Pint foram executadas nos containers. O acesso HTTP externo foi verificado pelo PowerShell do Windows.

Não há pendências de infraestrutura identificadas nas verificações acima. Bootstrap 5, modelagem e regras financeiras permanecem para uma etapa posterior aprovada. Nenhum volume foi removido e nenhum comando destrutivo foi executado.

## Etapa 02 — Esquema financeiro e phpMyAdmin

Foram adicionadas `wallets`, `operations` e `ledger_entries`, com seus models Eloquent. `User` ganhou apenas o relacionamento com a carteira. As três migrations novas foram aplicadas no lote 2 de `wallet_challenge`; as migrations padrão permanecem no lote 1 e não foram modificadas. O banco contém 12 tabelas. Nenhum reset ou exclusão de volume foi executado.

| Validação em 10/11/2026 | Resultado |
| --- | --- |
| Docker Compose | Configuração válida; aplicação, MySQL e phpMyAdmin ativos |
| phpMyAdmin | Versão 5.2.3, `127.0.0.1:8089:80`, autenticação `cookie`, sem login automático |
| Conexão a partir do phpMyAdmin | `mysql:3306`, MySQL 8.4.13, acesso a `wallet_challenge` e suas tabelas confirmado |
| HTTP pelo Windows | Aplicação `localhost:8088` e formulário de login `localhost:8089` responderam 200 |
| Migrations | Novas migrations aplicadas sem apagar dados em desenvolvimento e em `testing` |
| PHPUnit | 39 testes aprovados, 153 asserções; fixtures de integridade revertidas por transação |
| Integridade | Sete FKs restritivas, quatro restrições únicas de domínio, oito `CHECK`s e índices para consultas cronológicas |

O controle automatizado do navegador foi interrompido pela ferramenta por não conseguir confirmar a URL da janela no Windows. A validação visual e o login interativo no navegador permanecem pendentes; as verificações HTTP e de conexão a partir do container foram realizadas. A inclusão isolada da configuração do phpMyAdmin via CLI emitiu avisos por variáveis opcionais ausentes; o endpoint HTTP não exibiu esses avisos. A imagem Apache também emite aviso de `ServerName` ausente, sem bloquear o serviço.

Decisões e limites estão em [ARCHITECTURE.md](docs/ARCHITECTURE.md) e [BUSINESS_RULES.md](docs/BUSINESS_RULES.md). O saldo admite negativos; `amount_cents` exige valor positivo; o histórico de reversões usa referência original única e estados `completed`/`reversed`. O esquema não executa nem substitui autorização, conciliação, idempotência transacional ou controles concorrentes do futuro serviço. Nenhum serviço financeiro, controller, autenticação ou interface foi implementado. Aguardar revisão antes da próxima etapa.

## Etapa 03 — Autenticação e dashboard

Implementação com guard `web`, sessão nativa, Form Requests e serviço de cadastro transacional. Nenhum pacote PHP de autenticação foi adicionado. Bootstrap é a dependência de interface solicitada; `@popperjs/core` é uma dependência peer transitiva do próprio Bootstrap. Tailwind e Axios, não utilizados nestas telas, foram removidos. Apenas o componente Collapse do JavaScript Bootstrap é importado para a navegação móvel, sem plugins de interface adicionais.

| Validação em 10/11/2026 | Resultado |
| --- | --- |
| PHPUnit | 76 testes aprovados, 421 asserções, incluindo a suíte anterior |
| Pint PSR-12 | 44 arquivos PHP aprovados |
| Frontend | Build Vite concluído, Bootstrap 5.3.8 local, npm sem vulnerabilidades reportadas na instalação |
| Rotas | Sete rotas da aplicação conferidas pelo Artisan; `/up` do framework preservada |
| HTTP | Início, cadastro e login responderam 200 com assets locais |
| Banco e infraestrutura | Nenhuma migration alterada; containers, portas e volumes preservados |

O npm 12 informou o bloqueio automático do postinstall do esbuild; o build concluiu com sucesso usando o pacote disponível, sem desabilitar essa política. A validação visual pelo navegador não foi automatizada nesta etapa; os fluxos foram verificados por testes HTTP e requisições ao servidor local.

Não há depósitos, transferências, reversões, WalletService financeiro, recuperação de senha, painel administrativo ou integrações bancárias. Aguardar revisão antes de implementar movimentações.
