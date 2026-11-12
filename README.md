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

As instruções abaixo usam **Windows com PowerShell**. Tenha Docker Desktop com backend Linux/WSL2, Docker Compose v2 ou superior, PHP 8.3 de 64 bits e Composer 2 disponíveis. O PHP local precisa das extensões exigidas pelo lockfile, incluindo `mbstring`, `openssl`, `pdo`, `tokenizer`, `xml`, `dom`, `fileinfo`, `ctype` e `session`. `curl` e `zip` facilitam a instalação dos pacotes. O Composer verifica a compatibilidade durante a instalação.

O PHP e o Composer locais servem apenas para instalar o Sail no primeiro bootstrap. Depois, os comandos da aplicação rodam no container. Node.js local não é necessário, pois o runtime Sail fornece Node e npm. A primeira instalação precisa de acesso à internet para baixar pacotes e imagens. Deixe livres as portas `8088`, `8089` e `3309`.

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

O extrato consulta somente a carteira do usuário autenticado. Depósitos e transferências aceitam valores como `10`, `10,5` e `10,50`. O extrato exibe dez lançamentos por página. O botão **Reverter** pede confirmação e aparece nas operações elegíveis iniciadas pela própria conta.

O extrato identifica o destinatário nas transferências enviadas (`Para: nome`) e o remetente nas recebidas (`De: nome`), preservando a referência da operação. O nome exibido é o atual do cadastro, não uma cópia histórica; somente lançamentos da própria carteira são consultados.

## Testes e qualidade

Os campos de depósito e transferência possuem máscara em JavaScript: digite `1050` para obter `10,50`. Ao colar, use o valor em reais (`10`, `10,5` ou `10,50`), sem símbolo ou separador de milhares. A máscara usa strings e mantém a validação monetária no servidor. Seus testes podem ser executados com `docker compose exec -T --user sail laravel.test node --test tests/JavaScript/money-input.test.js`.

Os testes usam o banco MySQL separado `testing`, criado pelo script de inicialização do Sail em volumes novos. Antes dos testes, limpe o cache de configuração para aplicar as variáveis do ambiente de teste. Em uma instalação nova, aplique as migrations **nesse banco** antes da primeira execução.

```powershell
docker compose exec -T --user sail laravel.test php artisan config:clear
docker compose exec -T --user sail -e DB_DATABASE=testing -e DB_URL= laravel.test php artisan migrate --env=testing --no-interaction
```

Depois, execute a suíte, confira o padrão de código e, se modificar CSS ou JavaScript, refaça o build:

```powershell
docker compose exec -T --user sail -e DB_DATABASE=testing -e DB_URL= laravel.test php artisan test
docker compose exec -T --user sail laravel.test php vendor/bin/pint --test
docker compose exec -T --user sail laravel.test npm run build
```

Os testes de banco conferem o nome `testing` antes de abrir transações e revertem suas fixtures ao terminar. Cobrem autenticação, integridade relacional, valores monetários, idempotência, transferências, reversões e rotas HTTP. Em um volume antigo sem `testing`, será necessário provisionar esse banco separado e conceder acesso ao usuário de desenvolvimento antes desses comandos.

Para conferir o ambiente, execute os comandos abaixo.

```powershell
docker compose config --quiet
docker compose ps
docker compose exec -T --user sail laravel.test php artisan route:list -v
docker compose exec -T --user sail laravel.test php artisan migrate:status
```

## Documentação técnica e instalação verificada

- [Arquitetura](docs/ARCHITECTURE.md) descreve camadas, entidades, transações e integração HTTP.
- [Regras de negócio](docs/BUSINESS_RULES.md) registra o contrato financeiro implementado.
- [Decisões técnicas](docs/TECHNICAL_DECISIONS.md) reúne justificativas, evidências, limitações e explicações para entrevista, além dos resultados da auditoria da etapa 05.

A documentação necessária à avaliação está dentro deste repositório. O README utiliza apenas referências internas para os documentos do desafio.

Em 11/11/2026, a instalação foi executada em cópia isolada dos arquivos do projeto, com dependências instaladas pelos lockfiles, banco e volume novos, outro nome de Compose e portas alternativas. As migrations, o build, os 185 testes e os fluxos HTTP de cadastro, depósito, transferência e reversão passaram. O build Docker aproveitou cache local. Os detalhes e os limites dessa verificação estão no documento de decisões técnicas.

## Limitações

- Depósitos são simulados; não há PIX, cartões, gateway ou integração bancária. Também não há recuperação de senha nem painel administrativo.
- Uma reversão autorizada pode deixar saldo negativo, conforme a regra desta simulação.
- Os testes existentes verificam transações e colisões de chave, mas não reproduzem todos os cenários com processos concorrentes independentes.
- O serviço pressupõe dados financeiros consistentes; alterações manuais feitas diretamente no banco não são conciliadas ou reparadas automaticamente.

Este projeto está configurado para **desenvolvimento local**. Antes de qualquer uso público, são necessárias configurações próprias de produção, incluindo segredos, acesso ao banco e tratamento de erros.
