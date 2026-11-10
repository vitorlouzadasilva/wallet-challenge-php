# Wallet PHP — Grupo Adriano Cobuccio

Infraestrutura do desafio técnico Fullstack PHP do Grupo Adriano Cobuccio. O objetivo final é uma carteira financeira com cadastro, autenticação, depósitos, transferências e reversões, conforme o documento fornecido em `../regras_desafio/desafio fullstack PHP.docx`.

Esta entrega prepara somente o ambiente. A aplicação mantém a página inicial, o model `User`, as migrations e os testes padrão do Laravel. Modelagem financeira, autenticação e funcionalidades da carteira dependem de aprovação da próxima etapa.

## Stack

- PHP 8.3, Laravel 12 e Blade.
- Laravel Sail oficial, com runtime `vendor/laravel/sail/runtimes/8.3`; nenhum Dockerfile próprio.
- MySQL 8.4, da família MySQL 8, conforme o serviço oficial do Sail.
- Composer com autoload PSR-4 e dependências fixadas em `composer.lock`.
- PHPUnit e Laravel Pint com preset PSR-12 em `pint.json`.
- Bootstrap 5 previsto para a próxima etapa; ainda não instalado. Os arquivos frontend são os originais do skeleton Laravel, que inclui Tailwind/Vite sem instalação de pacotes Node nesta etapa.

## Requisitos

- Windows com PowerShell, Docker Desktop iniciado e backend Linux/WSL2 habilitado.
- Docker Engine e Docker Compose disponíveis no terminal.
- Git para versionamento.
- PHP 8.3 e Composer locais apenas para instalar dependências no primeiro bootstrap, caso `vendor` ainda não exista. Habilitar as extensões exigidas pelo Composer, incluindo `curl`, `mbstring`, `openssl`, `xml`, `zip` e PDO.
- Acesso à internet para baixar pacotes e construir as imagens oficiais.
- Portas locais 8088 e 3309 livres.

Após o bootstrap, executar os comandos PHP e Artisan dentro do Docker. Node não é necessário para abrir a página padrão nesta etapa.

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

Acesse **http://localhost:8088**. A primeira construção pode demorar devido aos pacotes incluídos pelo runtime oficial do Sail. O Docker Compose aguarda o healthcheck do MySQL antes de iniciar a aplicação.

## Serviços e persistência

| Serviço | Imagem ou runtime | Porta no host | Porta interna |
| --- | --- | --- | --- |
| `laravel.test` | Sail PHP 8.3 (`sail-8.3/app`) | `127.0.0.1:8088` | `80` |
| `mysql` | `mysql:8.4` | `127.0.0.1:3309` | `3306` |

O Laravel acessa `mysql:3306` na rede Docker `sail`, usando o banco `wallet_challenge` e o usuário `wallet_dev`. Clientes no Windows usam `127.0.0.1:3309`, com as credenciais do `.env`.

O volume nomeado `sail-mysql`, com nome efetivo `wallet-php_sail-mysql`, persiste `/var/lib/mysql`. O script oficial do Sail também cria o banco separado `testing` e concede acesso ao usuário de desenvolvimento para os testes.

As credenciais de inicialização do MySQL são aplicadas na criação de um volume vazio. Alterar apenas o `.env` não altera usuários e senhas de um volume já inicializado; investigar e planejar essa alteração preservando os dados.

Somente os dois serviços acima são configurados. E-mails usam o driver `log`; sessões, cache e filas usam o banco conforme os padrões do Laravel. Nenhum worker é iniciado nesta etapa.

## Testes e formatação

```powershell
docker compose exec -T --user sail laravel.test php artisan test
docker compose exec -T --user sail laravel.test php vendor/bin/pint --test
```

O `phpunit.xml`, configurado pelo Sail, usa `APP_ENV=testing` e `DB_DATABASE=testing`, separado do banco de desenvolvimento. Os testes padrão verificam uma asserção unitária e a resposta HTTP da página inicial; não cobrem regras financeiras. A conexão MySQL e as migrations são verificadas separadamente.

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
