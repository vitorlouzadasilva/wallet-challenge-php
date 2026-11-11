# Wallet PHP — carteira financeira

Aplicação web desenvolvida para o desafio Fullstack PHP do Grupo Adriano Cobuccio. É uma **simulação de carteira digital**: cada pessoa cria sua conta, acompanha o saldo e pode registrar depósitos, transferências para outra conta e reversões. Não há movimentação de dinheiro real.

## O que a aplicação oferece

- Cadastro com criação automática de uma carteira individual, inicialmente com saldo zero.
- Login e logout por sessão, com dashboard acessível apenas ao usuário autenticado.
- Depósito na própria carteira e transferência para outro usuário identificado pelo e-mail.
- Extrato paginado com débitos, créditos, situação e referência das operações.
- Reversão de depósito ou transferência pelo usuário que iniciou a operação, com preservação do histórico original.

Para conhecer o fluxo, crie duas contas pelo formulário em `/register`. Na primeira, faça um depósito de `10,50`, transfira `2,00` para o e-mail da segunda e consulte o extrato de ambas. Volte à primeira conta para testar **Reverter**. Não é necessário seed nem existem credenciais de demonstração.

## Tecnologias

PHP 8.3, Laravel 12, MySQL 8.4/InnoDB e Laravel Sail em Docker Compose. A interface usa Blade e Bootstrap 5 local, compilado pelo Vite. PHPUnit cobre regras e fluxos HTTP; Laravel Pint aplica PSR-12.

## Instalação local

As instruções abaixo usam **Windows com PowerShell**. Tenha Docker Desktop com backend Linux/WSL2, PHP 8.3 e Composer disponíveis. O PHP e o Composer locais servem apenas para instalar o Sail no primeiro bootstrap; depois, os comandos da aplicação rodam no container. Node.js local não é necessário. Deixe livres as portas `8088`, `8089` e `3309`.

No diretório do projeto, instale as dependências PHP e crie o arquivo de ambiente se ele ainda não existir:

```powershell
composer install --no-scripts --no-interaction --prefer-dist
if (!(Test-Path -LiteralPath .env)) { Copy-Item -LiteralPath .env.example -Destination .env }
```

Edite o `.env` e preencha **`DB_PASSWORD` e `MYSQL_ROOT_PASSWORD` com senhas locais, diferentes entre si**. Preserve as demais configurações de banco do exemplo: `DB_HOST=mysql`, `DB_DATABASE=wallet_challenge` e `DB_USERNAME=wallet_dev`. O `.env` não é versionado. Se já existir um ambiente em uso, mantenha suas senhas e a `APP_KEY` atuais.

Com o Docker Desktop iniciado, suba os serviços e conclua a instalação dentro do container:

```powershell
docker compose config --quiet
docker compose up -d --build --wait --wait-timeout 180
docker compose exec -T --user sail laravel.test composer install --no-interaction --prefer-dist
```

**Somente em uma instalação nova, com `APP_KEY` ainda vazia**, gere a chave da aplicação. Não regenere a chave de uma instalação já utilizada.

```powershell
docker compose exec -T --user sail laravel.test php artisan key:generate
```

Aplique as migrations pendentes e compile os assets:

```powershell
docker compose exec -T --user sail laravel.test php artisan migrate
docker compose exec -T --user sail laravel.test npm ci
docker compose exec -T --user sail laravel.test npm run build
```

Abra **http://localhost:8088**. Os serviços e portas são:

| Serviço | Endereço local | Uso |
| --- | --- | --- |
| Aplicação | http://localhost:8088 | Cadastro, login e carteira |
| phpMyAdmin | http://localhost:8089 | Consulta do banco de desenvolvimento |
| MySQL | `127.0.0.1:3309` | Conexão por cliente SQL no computador |

No phpMyAdmin, entre normalmente com `DB_USERNAME` e `DB_PASSWORD` do seu `.env` e selecione `wallet_challenge`. A interface do banco fica restrita ao computador local. O volume do MySQL preserva os dados entre reinicializações dos containers.

## Como a carteira funciona

O Laravel organiza rotas, controllers, Form Requests, models Eloquent e views Blade. O controller recebe o usuário da sessão e converte o valor digitado, como `10,50`, em **1.050 centavos inteiros**, sem cálculos com ponto flutuante. A carteira de origem nunca é escolhida pelo formulário.

O `WalletService` executa depósitos, transferências e reversões em transações SQL. Ele bloqueia os registros necessários antes de conferir e atualizar saldos, grava a operação e seus lançamentos no mesmo commit e usa uma chave idempotente única para impedir que a repetição de uma solicitação duplique efeitos financeiros. A reversão registra lançamentos compensatórios e mantém a operação original para auditoria.

O extrato consulta somente a carteira do usuário autenticado. As regras completas, relacionamentos e decisões técnicas estão em [Regras de negócio](docs/BUSINESS_RULES.md) e [Arquitetura](docs/ARCHITECTURE.md).

## Testes e qualidade

Os testes usam o banco MySQL separado `testing`. Em uma instalação nova, aplique as migrations **nesse banco** antes da primeira execução; o comando não apaga o banco de desenvolvimento:

```powershell
docker compose exec -T --user sail -e DB_DATABASE=testing laravel.test php artisan migrate --env=testing --no-interaction
```

Depois, execute a suíte, confira o padrão de código e, se modificar CSS ou JavaScript, refaça o build:

```powershell
docker compose exec -T --user sail laravel.test php artisan test
docker compose exec -T --user sail laravel.test php vendor/bin/pint --test
docker compose exec -T --user sail laravel.test npm run build
```

Os testes de banco isolam suas alterações por transações e cobrem autenticação, integridade relacional, valores monetários, idempotência, transferências, reversões e rotas HTTP. Para consultar o estado da aplicação: `docker compose ps` e `docker compose exec -T --user sail laravel.test php artisan migrate:status`.

## Limitações

- Depósitos são simulados; não há PIX, cartões, gateway ou integração bancária. Também não há recuperação de senha nem painel administrativo.
- Uma reversão autorizada pode deixar saldo negativo, conforme a regra desta simulação.
- Os testes existentes verificam transações e colisões de chave, mas não reproduzem todos os cenários com processos concorrentes independentes.
- O serviço pressupõe dados financeiros consistentes; alterações manuais feitas diretamente no banco não são conciliadas ou reparadas automaticamente.

Este projeto está configurado para **desenvolvimento local**. Antes de qualquer uso público, são necessárias configurações próprias de produção, incluindo segredos, acesso ao banco e tratamento de erros.
