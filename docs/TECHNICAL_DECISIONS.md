# Decisões técnicas da carteira PHP

## Escopo e evidências

Documento preparado em 11/11/2026 para apresentação e code review do desafio do Grupo Adriano Cobuccio. As decisões descrevem a implementação inspecionada na etapa 05. As evidências são o código, os lockfiles, os testes, os documentos internos e os commits das etapas 01 a 04B, de `adf701b` a `b19ff0a`.

O histórico registra as escolhas implementadas e algumas justificativas. Quando uma comparação histórica está ausente, isso aparece no campo de alternativas. As comparações adicionais desta auditoria estão identificadas como análise atual. As necessidades de produção ao final permanecem como trabalho futuro.

## Stack e ambiente

### PHP 8.3, Laravel 12 e Composer

| Aspecto | Registro |
| --- | --- |
| Contexto/problema | Entregar cadastro, sessão e movimentações financeiras no ecossistema exigido pelo desafio, dentro do prazo. |
| Solução escolhida | PHP `~8.3.0`, Laravel `^12.0`, Composer e autoload PSR-4 para `App`, factories, seeders e testes. O lockfile fixa Laravel 12.72.0. |
| Alternativas efetivamente consideradas | As diretrizes estabelecem PHP 8.3 e Laravel 12. O histórico consultado carece de comparação com outros frameworks. |
| Justificativa técnica | Os recursos nativos atendem a HTTP, validação, sessão, hash, ORM, transações e testes. PSR-4 permite localizar classes pelo namespace e carregá-las pelo Composer. |
| Consequências e limitações | A aplicação depende de PHP 8.3 de 64 bits e das extensões verificadas pelo Composer. Atualizações de dependências exigem nova validação. |
| Arquivos relevantes | `composer.json`, `composer.lock`, `bootstrap/app.php` e `app/`. |
| Explicação para entrevista | “Usei a stack definida pelo desafio e os recursos do Laravel para concentrar o esforço nas regras financeiras e na integração web.” |

### Docker e runtime oficial Laravel Sail

| Aspecto | Registro |
| --- | --- |
| Contexto/problema | Padronizar o runtime da aplicação e os serviços locais para o avaliador. |
| Solução escolhida | Compose com `laravel.test`, `mysql` e `phpmyadmin`. A imagem da aplicação é construída a partir de `vendor/laravel/sail/runtimes/8.3`. Comandos da aplicação executam como `sail`. |
| Alternativas efetivamente consideradas | O README prevê PHP e Composer locais para o bootstrap e execução posterior no container. O runtime Sail é uma diretriz do projeto. |
| Justificativa técnica | Reutilizar o Dockerfile distribuído com Sail evita manter um runtime próprio durante o desafio. O serviço MySQL possui healthcheck e as dependências aguardam sua inicialização. |
| Consequências e limitações | É preciso instalar `vendor` antes de construir a imagem. O diretório é montado no container, com desempenho dependente do host. Tags de imagens e repositórios do sistema operacional continuam mutáveis, portanto a reprodução funcional depende também desses fornecedores. |
| Arquivos relevantes | `compose.yaml`, `.env.example`, `composer.lock` e `README.md`. |
| Explicação para entrevista | “O avaliador instala o Sail pelo Composer e executa a aplicação no mesmo tipo de ambiente Docker usado na validação.” |

### MySQL 8.4 e InnoDB

| Aspecto | Registro |
| --- | --- |
| Contexto/problema | Persistir operações relacionadas com atomicidade, unicidade, FKs e bloqueio de linhas. |
| Solução escolhida | MySQL 8.4 com tabelas financeiras InnoDB. O ambiente auditado executou MySQL 8.4.13. |
| Alternativas efetivamente consideradas | A arquitetura registra a escolha de executar os testes de esquema no MySQL e sua dependência dos CHECKs desse banco. Outros SGBDs carecem de avaliação histórica documentada. |
| Justificativa técnica | InnoDB oferece as transações e leituras bloqueadas usadas pelo serviço. Executar testes no mesmo SGBD exercita restrições e erros nativos usados na recuperação de colisões. |
| Consequências e limitações | SQL dos CHECKs e interpretação do erro 1062 vinculam parte da solução ao MySQL. A configuração da conexão habilita modo estrito. A auditoria observou REPEATABLE READ no ambiente verificado. |
| Arquivos relevantes | `compose.yaml`, `config/database.php`, `app/Services/WalletService.php` e `tests/Feature/WalletSchemaTest.php`. |
| Explicação para entrevista | “Validei as garantias financeiras e as restrições no MySQL utilizado pela aplicação.” |

### phpMyAdmin local com autenticação

| Aspecto | Registro |
| --- | --- |
| Contexto/problema | Permitir inspeção do banco durante desenvolvimento e avaliação. |
| Solução escolhida | Imagem `phpmyadmin:5.2.3-apache`, publicada em `127.0.0.1:8089`, com host MySQL interno e login por credenciais digitadas. |
| Alternativas efetivamente consideradas | As diretrizes exigem restrição ao loopback e login manual. A arquitetura registra essa escolha explicitamente. |
| Justificativa técnica | A interface facilita a conferência de operações, lançamentos e restrições em ambiente local. As credenciais permanecem no `.env` de cada instalação. |
| Consequências e limitações | Quem possui acesso SQL de manutenção consegue alterar dados diretamente. O modelo de histórico depende também das permissões operacionais do banco. |
| Arquivos relevantes | `compose.yaml`, `.env.example`, `.gitignore` e `README.md`. |
| Explicação para entrevista | “Disponibilizei uma ferramenta de inspeção local, com autenticação e porta vinculada ao computador do avaliador.” |

### Blade, Bootstrap 5 e Vite

| Aspecto | Registro |
| --- | --- |
| Contexto/problema | Entregar formulários, dashboard e extrato responsivos dentro do prazo. |
| Solução escolhida | Renderização Blade no servidor, Bootstrap 5.3.8 instalado localmente e Vite 7.3.8 no lockfile. O JavaScript importa o componente Collapse. |
| Alternativas efetivamente consideradas | A arquitetura registra assets locais e ausência de CDN. Uma comparação histórica com SPA carece de registro. |
| Justificativa técnica | A navegação por sessão e formulários POST integra diretamente com validação, CSRF e mensagens do Laravel. Vite produz assets versionados por hash para CSS e JavaScript. |
| Consequências e limitações | Cada navegação consulta o servidor. O build precisa existir para o carregamento normal das views. Blade escapa os textos e a revisão acrescentou proteção de tipo para valores antigos dos formulários. |
| Arquivos relevantes | `resources/views/`, `resources/js/app.js`, `resources/css/app.css`, `vite.config.js`, `package.json` e `package-lock.json`. |
| Explicação para entrevista | “Blade e Bootstrap cobriram os fluxos solicitados com uma integração direta à sessão e à validação do servidor.” |

## Arquitetura

### MVC, serviços concretos e injeção de dependências

| Aspecto | Registro |
| --- | --- |
| Contexto/problema | Separar transporte HTTP, apresentação, persistência e regras financeiras. |
| Solução escolhida | Rotas apontam para controllers, Form Requests validam entrada, views apresentam dados e `WalletService` coordena movimentações. `RegisterUser` coordena cadastro. O container resolve os serviços concretos por injeção. |
| Alternativas efetivamente consideradas | As diretrizes e a arquitetura determinam avaliar patterns pela necessidade concreta e registram que Repository Pattern e interfaces adicionais seriam desnecessários no escopo atual. |
| Justificativa técnica | Controllers coordenam a chamada e a resposta. O serviço financeiro pode ser exercitado diretamente nos testes e concentra autorização de domínio, transações e saldos. Eloquent atende ao acesso a dados existente. |
| Consequências e limitações | O serviço usa Eloquent e facades Laravel, mantendo dependência do framework e do esquema. SOLID aparece na divisão de responsabilidades e na injeção, sem uma camada de interfaces para cada classe. |
| Arquivos relevantes | `app/Http/Controllers/`, `app/Http/Requests/`, `app/Services/RegisterUser.php`, `app/Services/WalletService.php` e `app/Models/`. |
| Explicação para entrevista | “O controller coordena a requisição. O serviço concentra a unidade financeira e suas garantias, permitindo testar essas regras diretamente.” |

### Eloquent, migrations e restrições de esquema

| Aspecto | Registro |
| --- | --- |
| Contexto/problema | Evoluir o esquema de forma rastreável e manter integridade também durante colisões e falhas. |
| Solução escolhida | Models Eloquent com relacionamentos e casts, migrations executadas pelo Artisan, FKs restritivas, índices UNIQUE e oito CHECKs financeiros. O SQL estático dos CHECKs usa `DB::statement()`. |
| Alternativas efetivamente consideradas | A arquitetura registra a limitação da API de CHECK do Schema Builder e o uso de SQL estático para MySQL 8.4. A necessidade atual é atendida pelo Eloquent. |
| Justificativa técnica | Migrations permitem instalar tabelas e restrições em banco vazio. UNIQUE e FKs complementam verificações de aplicação quando há disputa ou escrita inválida. |
| Consequências e limitações | A instalação requer permissões para DDL. FKs impedem excluir pais referenciados. A igualdade entre saldo e lançamentos é mantida pelo serviço, sem restrição agregada no banco. |
| Arquivos relevantes | `database/migrations/`, `app/Models/`, `tests/Feature/WalletSchemaTest.php` e `config/database.php`. |
| Explicação para entrevista | “Versionei o esquema com migrations e coloquei no banco as restrições que ele consegue garantir diretamente.” |

### Form Requests, conversão e autorização por sessão

| Aspecto | Registro |
| --- | --- |
| Contexto/problema | Tratar entrada não confiável e impedir que o cliente escolha o titular da origem financeira. |
| Solução escolhida | Requests validam textos e UUIDs. `ReaisToCents` converte representação brasileira por manipulação de dígitos e comparação de limite. O controller obtém o ator por `$request->user()` e resolve o e-mail do destino no servidor. O serviço revalida propriedade e autorização. |
| Alternativas efetivamente consideradas | As regras e a arquitetura registram origem descoberta no servidor e autorização no serviço. Uma camada Policy/Gate adicional foi considerada desnecessária na etapa atual. |
| Justificativa técnica | O contrato financeiro continua válido em chamadas diretas ao serviço. A validação HTTP identifica erros antes de movimentar dados e mantém mensagens próximas ao formulário. |
| Consequências e limitações | O chamador do serviço deve fornecer um ator autenticado confiável. No HTTP, somente e-mail, valor, chave e ID da operação de reversão participam da solicitação. Campos extras de saldo, usuário e origem são ignorados. |
| Arquivos relevantes | `app/Http/Requests/Wallet/`, `app/Http/Controllers/WalletController.php`, `app/Support/ReaisToCents.php` e `app/Services/WalletService.php`. |
| Explicação para entrevista | “A identidade vem da sessão e a origem é descoberta no servidor. O serviço verifica novamente se o ator pode executar a movimentação.” |

## Segurança HTTP

### Autenticação Laravel, sessões no servidor e bcrypt

| Aspecto | Registro |
| --- | --- |
| Contexto/problema | Autenticar pessoas e proteger credenciais e continuidade da sessão. |
| Solução escolhida | Guard `web`, provider Eloquent, `Auth::attempt()`, `Hash::make()` com bcrypt e sessão em tabela no servidor. Login e cadastro regeneram a sessão. Logout invalida a sessão e regenera CSRF. O exemplo usa custo bcrypt 12 e os testes usam custo 4. |
| Alternativas efetivamente consideradas | A implementação e as regras registram autenticação por sessão e os limites do bcrypt. Comparações históricas com tokens ou outros algoritmos carecem de evidência. |
| Justificativa técnica | Os mecanismos nativos atendem ao navegador e integram com os middlewares. A regra compartilhada limita senhas a 72 bytes e, após esta revisão, rejeita NUL em cadastro e login. |
| Consequências e limitações | O cadastro exige pelo menos 12 caracteres. Sessões possuem expiração de 120 minutos e cookie HttpOnly/SameSite Lax conforme configuração. A configuração local usa HTTP e sessão sem criptografia do payload no banco. HTTPS e parâmetros de produção exigem implantação própria. |
| Arquivos relevantes | `config/auth.php`, `config/session.php`, `.env.example`, `app/Http/Controllers/Auth/`, `app/Http/Requests/Auth/`, `app/Rules/PasswordByteLimit.php` e `tests/Feature/AuthenticationTest.php`. |
| Explicação para entrevista | “Usei a autenticação nativa por sessão, regenerei a sessão após autenticar e validei as limitações do bcrypt antes de processar a senha.” |

### CSRF e rate limiting

| Aspecto | Registro |
| --- | --- |
| Contexto/problema | Proteger ações autenticadas contra solicitações forjadas e limitar repetição abusiva. |
| Solução escolhida | Middleware `web`, `@csrf` e ações mutáveis por POST. Login limita cinco falhas por e-mail/IP e vinte por IP em 60 segundos, com chaves em hash. Operações financeiras compartilham limite de doze solicitações por minuto por conta. |
| Alternativas efetivamente consideradas | A arquitetura registra limites por par e por IP para impedir o desvio do limite por troca de e-mail, além de distinguir rate limiting e idempotência. |
| Justificativa técnica | Os limitadores nativos e o cache existente atendem ao volume do desafio. O servidor valida CSRF antes de executar ações financeiras. |
| Consequências e limitações | Validações rejeitadas antes de autenticar ficam fora do contador de falhas do login. Cadastro carece de limitador específico. Testes habilitam explicitamente CSRF para verificar sua rejeição fora do bypass de testes. Avaliação de abuso e capacidade em produção permanece pendente. |
| Arquivos relevantes | `routes/web.php`, `app/Providers/AppServiceProvider.php`, `app/Http/Requests/Auth/LoginRequest.php`, `resources/views/`, `tests/Feature/AuthenticationTest.php` e `tests/Feature/WalletWebTest.php`. |
| Explicação para entrevista | “CSRF protege a origem das ações por sessão. Os limites reduzem abuso no login e nas movimentações, e a idempotência protege contra duplicação financeira.” |

### Exceções, logs sanitizados e exposição de dados

| Aspecto | Registro |
| --- | --- |
| Contexto/problema | Informar rejeições úteis e tratar falhas internas sem divulgar SQL ou credenciais nas mensagens financeiras. |
| Solução escolhida | Exceções de domínio recebem mensagens específicas. `WalletController` captura `Throwable`, registra classe, arquivo e linha das falhas inesperadas e redireciona com erro genérico. Mantém a chave válida para replay. O serviço propaga falhas depois do rollback. |
| Alternativas efetivamente consideradas | A arquitetura exige erro público genérico e diagnóstico sanitizado. Nesta auditoria, foi avaliada a troca do catch amplo por propagação ao handler global. O comportamento existente foi preservado após testes da resposta e do log. |
| Justificativa técnica | A captura ocorre na fronteira HTTP e traduz a falha em erro de formulário. O caminho financeiro de sucesso depende do retorno do serviço. Os testes adicionais cobrem `RuntimeException`, `Error` e falha de persistência com causa encadeada. |
| Consequências e limitações | O redirecionamento usa HTTP 302 e exige inspecionar a mensagem de erro na interface. A localização e a classe oferecem diagnóstico limitado. Há um intervalo entre commit e entrega da resposta em que falhas de transporte exigem repetir a mesma chave. O modo local `APP_DEBUG=true` ainda permite páginas detalhadas em falhas fora desse tratamento. |
| Arquivos relevantes | `app/Http/Controllers/WalletController.php`, `app/Exceptions/Wallet/`, `app/Services/WalletService.php`, `bootstrap/app.php`, `.env.example` e `tests/Feature/WalletWebTest.php`. |
| Explicação para entrevista | “Mantive o catch amplo porque ele traduz falhas em erro genérico na borda HTTP e registra um diagnóstico sanitizado. Verifiquei esse comportamento com testes adicionais.” |

### Extrato individual e mass assignment controlado

| Aspecto | Registro |
| --- | --- |
| Contexto/problema | Evitar leitura de carteira alheia e escrita de campos financeiros escolhidos pelo cliente. |
| Solução escolhida | Dashboard consulta a relação do usuário autenticado e pagina dez lançamentos por vez, com carregamento antecipado das operações. Models financeiros mantêm proteção padrão de mass assignment. O serviço atribui dados canônicos internos. `User` permite somente nome, e-mail e senha e oculta senha e remember token na serialização. |
| Alternativas efetivamente consideradas | As regras registram consulta pela sessão e rejeitam a seleção da origem pelo payload. Outros modelos de autorização carecem de comparação histórica. |
| Justificativa técnica | O escopo da consulta nasce da identidade autenticada. O serviço calcula participantes, deltas e saldo a partir do banco. Blade escapa os valores apresentados. |
| Consequências e limitações | O destinatário vê o lançamento recebido, sua operação e referências necessárias. A paginação evita carregar todo o histórico de uma vez. Os testes cobrem IDs alheios, XSS no nome e tentativas de alterar saldo e origem. |
| Arquivos relevantes | `app/Http/Controllers/DashboardController.php`, `app/Models/`, `resources/views/dashboard.blade.php`, `tests/Feature/AuthenticationTest.php` e `tests/Feature/WalletWebTest.php`. |
| Explicação para entrevista | “O extrato sempre parte da carteira da sessão. A origem da movimentação e os campos financeiros são definidos pelo servidor.” |

## Modelagem e integridade financeira

### Carteira individual, operações, lançamentos e saldo materializado

| Aspecto | Registro |
| --- | --- |
| Contexto/problema | Consultar saldo rapidamente e manter a origem de cada crédito e débito. |
| Solução escolhida | Cadastro cria usuário e carteira com saldo zero na mesma transação. `wallets.user_id` é único. `operations` guarda o evento financeiro e `ledger_entries` guarda os efeitos por carteira. `wallets.balance_cents` materializa a soma desses efeitos. |
| Alternativas efetivamente consideradas | A arquitetura registra saldo materializado e a necessidade de preservar a igualdade com os lançamentos. Uma comparação histórica com cálculo integral a cada consulta carece de evidência. |
| Justificativa técnica | O dashboard lê um saldo persistido e o extrato mantém a trilha dos efeitos. O serviço atualiza saldo e histórico conjuntamente. Essa divisão atende às consultas e à auditoria do desafio. |
| Consequências e limitações | UNIQUE garante no máximo uma carteira por usuário. Usuários legados fora do cadastro podem estar sem carteira. Escritas SQL externas podem romper a igualdade. Depósitos simulados possuem apenas o crédito na carteira, sem conta externa de contrapartida. |
| Arquivos relevantes | `app/Services/RegisterUser.php`, `app/Services/WalletService.php`, `app/Models/`, `database/migrations/` e `docs/BUSINESS_RULES.md`. |
| Explicação para entrevista | “Mantive saldo para consulta direta e lançamentos para explicar sua composição. Ambos são atualizados na mesma transação.” |

### BIGINT SIGNED em centavos e aritmética inteira

| Aspecto | Registro |
| --- | --- |
| Contexto/problema | Representar valores com precisão de centavo, incluindo saldos negativos autorizados e limites numéricos. |
| Solução escolhida | Saldo, valor e delta usam BIGINT assinado. PHP de 64 bits trabalha com inteiros. A entrada e a formatação manipulam dígitos. O serviço confere overflow e underflow antes das somas. |
| Alternativas efetivamente consideradas | As regras e a arquitetura rejeitam FLOAT/DOUBLE para valores monetários. O histórico consultado carece de comparação com DECIMAL. A comparação a seguir foi realizada nesta auditoria. |
| Justificativa técnica | Centavos inteiros oferecem exatidão para uma moeda com duas casas e permitem soma e comparação nativas, com limites explícitos. DECIMAL também preserva precisão decimal no banco, mas exigiria definir escala e manter operações decimais exatas no PHP, usualmente por strings e aritmética apropriada. |
| Consequências e limitações | O intervalo de saldo vai de −9.223.372.036.854.775.808 a 9.223.372.036.854.775.807 centavos. Valores de operação são positivos até o máximo. O modelo assume reais e precisão de centavo. Novas moedas ou frações exigiriam revisão do contrato. |
| Arquivos relevantes | Migrations financeiras, `app/Services/WalletService.php`, `app/Support/ReaisToCents.php`, `app/Support/BrazilianCurrency.php` e `tests/Unit/`. |
| Explicação para entrevista | “Como o desafio usa reais com duas casas, armazenei centavos inteiros e validei os limites antes de calcular. DECIMAL seria uma opção exata, com outro contrato de aritmética no PHP.” |

### Transações SQL, rollback e persistência verificada

| Aspecto | Registro |
| --- | --- |
| Contexto/problema | Evitar débito sem crédito, lançamento sem saldo ou reversão com status parcial. |
| Solução escolhida | `DB::transaction()` envolve cada unidade financeira. O helper de persistência verifica o retorno de `save()`. Falhas desfazem operação, lançamentos, saldos e mudança de status. O Laravel recebe até três tentativas para erros de concorrência suportados. |
| Alternativas efetivamente consideradas | As regras proíbem separar os lados da transferência em transações diferentes. Os testes e a arquitetura documentam cancelamento de eventos Eloquent e falhas intermediárias. |
| Justificativa técnica | A unidade SQL coincide com a unidade de negócio. A validação de persistência evita interpretar cancelamento de `save()` como movimentação concluída. |
| Consequências e limitações | O uso HTTP deixa o serviço controlar a transação externa. Em transação aninhada, savepoints e o commit final dependem do chamador. Rollbacks podem deixar lacunas em AUTO_INCREMENT. |
| Arquivos relevantes | `app/Services/WalletService.php`, `tests/Feature/WalletServiceTest.php` e `tests/Feature/WalletWebTest.php`. |
| Explicação para entrevista | “Toda a movimentação é confirmada em uma transação. Uma falha em qualquer lançamento desfaz as escritas anteriores da tentativa.” |

### Locks FOR UPDATE e ordem de bloqueio

| Aspecto | Registro |
| --- | --- |
| Contexto/problema | Evitar decisões financeiras baseadas em saldo anterior a outra escrita e reduzir deadlocks entre carteiras. |
| Solução escolhida | Consultas individuais por PK com `lockForUpdate()`, adquiridas em ordem numérica crescente de carteira. Propriedade e saldo são conferidos depois dos locks. Reversões bloqueiam primeiro a operação original. |
| Alternativas efetivamente consideradas | A arquitetura compara consultas individuais ordenadas com depender do plano de um `WHERE IN`. A ordem explícita foi escolhida para tornar a aquisição verificável no código. |
| Justificativa técnica | A carteira permanece bloqueada enquanto saldo e lançamentos são atualizados. A ordem comum reduz disputas circulares entre operações sobre as mesmas carteiras. |
| Consequências e limitações | Deadlocks continuam possíveis por índices e outros locks. O serviço depende do tratamento transacional e das tentativas do Laravel. A suíte atual é sequencial e carece de medição de interleavings entre processos independentes. |
| Arquivos relevantes | `lockWallets()`, `transfer()`, `reverse()` e `transact()` em `app/Services/WalletService.php`. |
| Explicação para entrevista | “Bloqueio as carteiras pela chave primária, sempre na mesma ordem, e só então verifico o saldo. Isso reduz deadlocks e protege a decisão de débito.” |

### Idempotência e restrições UNIQUE

| Aspecto | Registro |
| --- | --- |
| Contexto/problema | Evitar duplicação por reenvio de formulário, repetição de rede e colisão da mesma chave. |
| Solução escolhida | UUIDs por formulário no HTTP e chave global única no banco. O serviço compara ator, tipo, valor, participantes e referência original. A operação reserva a chave antes da validação dependente de saldo e das escritas financeiras. Erro 1062 do índice idempotente é tratado após rollback, com uma recuperação e leitura atual bloqueada da vencedora. |
| Alternativas efetivamente consideradas | A arquitetura justifica reserva antes da validação de saldo e leitura atual para recuperação em snapshot antigo. Chaves legadas do serviço continuam aceitas conforme o contrato de até 128 caracteres. |
| Justificativa técnica | UNIQUE resolve a disputa de persistência. A comparação de conteúdo impede reutilizar a chave para outra ação ou ator. O rollback anterior à recuperação mantém a tentativa perdedora consistente. |
| Consequências e limitações | A chave é global e sensível a maiúsculas. Colisões de conteúdo retornam erro genérico. A interpretação do erro depende do índice MySQL. Valores antigos preservam a chave válida após falhas e POST/Redirect/GET produz formulários novos após sucesso. A recuperação foi testada de forma sequencial. |
| Arquivos relevantes | `app/Services/WalletService.php`, migration de `operations`, `app/Http/Controllers/DashboardController.php`, Requests financeiros, `resources/views/dashboard.blade.php` e testes financeiros. |
| Explicação para entrevista | “A mesma chave só recupera a mesma operação do mesmo ator. O banco arbitra a disputa pela chave e a tentativa perdedora é revertida antes de recuperar a vencedora.” |

### Reversões compensatórias e saldos negativos

| Aspecto | Registro |
| --- | --- |
| Contexto/problema | Desfazer o efeito financeiro preservando a operação e os lançamentos usados na auditoria. |
| Solução escolhida | Nova operação `reversal`, com referência única à original e lançamentos opostos. A original muda para `reversed`. O serviço exige o iniciador original e revalida sua propriedade. Verifica o histórico original antes de compensar e permite saldo negativo dentro de BIGINT. |
| Alternativas efetivamente consideradas | As regras estabelecem compensação, preservação dos lançamentos e reversão independente do saldo disponível. A arquitetura justifica o índice único da referência original para reversões confirmadas. |
| Justificativa técnica | O histórico explica o movimento original e sua compensação. A restrição de saldo das transferências comuns continua aplicada a novas transferências. Depósitos posteriores somam ao saldo negativo existente. |
| Consequências e limitações | Cada original admite uma reversão. Operações de reversão permanecem `completed`. A original conserva valor, participantes, chave e lançamentos, com mudança de status e `updated_at`. Reverter pode gerar dívida nesta simulação. Regras de disputa e autorização administrativa demandariam novos requisitos. |
| Arquivos relevantes | `reverse()` e auxiliares em `app/Services/WalletService.php`, migration de `operations`, `docs/BUSINESS_RULES.md` e testes de reversão. |
| Explicação para entrevista | “A reversão cria lançamentos opostos e mantém os originais. O desafio permite saldo negativo para compensar até valores já utilizados pelo destinatário.” |

## Qualidade e entrega

### PHPUnit com MySQL isolado

| Aspecto | Registro |
| --- | --- |
| Contexto/problema | Validar regras financeiras críticas preservando os dados usados na demonstração. |
| Solução escolhida | PHPUnit 11.5.57 no lockfile, unitários para conversão e formatação e testes de integração no banco MySQL `testing`. Os testes de banco verificam driver/nome antes de escrever e usam transações para desfazer fixtures. |
| Alternativas efetivamente consideradas | A arquitetura registra a escolha de MySQL para exercitar as restrições reais e documenta a simulação sequencial de colisão. |
| Justificativa técnica | Os testes cobrem saldo, centavos, limites, autorização, idempotência, rollback, reversões e rotas normais. Na revisão foram adicionadas regressões para NUL, reapresentação de arrays inválidos e sanitização de erros internos. |
| Consequências e limitações | As migrations de `testing` precisam estar aplicadas. Vite é dispensado nos testes HTTP, exigindo build e smoke HTTP separados. A suíte carece de concorrência multiprocessos, carga e comprovação de todos os interleavings. |
| Arquivos relevantes | `phpunit.xml`, `tests/TestCase.php`, `tests/Unit/`, `tests/Feature/` e `README.md`. |
| Explicação para entrevista | “Testei as regras no MySQL e isolei fixtures em transações no banco testing. A suíte cobre atomicidade e colisões sequenciais, com concorrência multiprocessos como limitação explícita.” |

### Laravel Pint e PSR-12

| Aspecto | Registro |
| --- | --- |
| Contexto/problema | Manter estilo legível e uniforme sem consumir a revisão com diferenças de formatação. |
| Solução escolhida | Laravel Pint 1.32.1 no lockfile, com preset `psr12`. Verificação por `php vendor/bin/pint --test`. |
| Alternativas efetivamente consideradas | O preset é definido nas diretrizes. O histórico consultado carece de comparação com outros formatadores. |
| Justificativa técnica | A ferramenta existente aplica a convenção escolhida de forma repetível e adequada ao tamanho do projeto. |
| Consequências e limitações | Formatação é uma verificação de estilo. A correção das regras depende também de testes e inspeção. Alterações da etapa 05 ficaram restritas aos defeitos reproduzidos, regressões e documentação. |
| Arquivos relevantes | `pint.json`, `composer.json`, `composer.lock` e fontes PHP. |
| Explicação para entrevista | “O Pint aplica o preset PSR-12 definido para o projeto e deixa a revisão concentrada nas decisões e no comportamento.” |

## Correções verificadas na etapa 05

### Senhas com byte nulo

O teste de cadastro reproduziu uma exceção de hash ao receber NUL interno. O teste de login mostrou aceitação de uma senha válida seguida de NUL e sufixo. A regra compartilhada agora rejeita esse byte antes do processamento em ambos os formulários. A senha permanece excluída dos dados reapresentados na sessão. A correção afeta `PasswordByteLimit` e possui regressões em `AuthenticationTest`.

### Reapresentação de entrada com tipo inválido

Uma transferência com arrays em campos textuais era rejeitada pelos Requests, mas o GET seguinte produzia erro 500 ao escapar esses arrays na view. As views de dashboard, cadastro e login agora reapresentam apenas strings. O identificador antigo de reversão é validado antes da comparação. Entradas válidas e suas chaves continuam preservadas. Testes cobrem o retorno às páginas após essas rejeições.

### Tratamento de Throwable

A inspeção e os testes adicionais sustentaram a manutenção do tratamento existente em `WalletController`. Os testes exigem mensagem pública genérica, ausência de sucesso na sessão, preservação da chave e contexto de log restrito a classe, arquivo e linha. As falhas internas cobertas incluem erro de programação e exceção de persistência com causa encadeada. O rollback financeiro continua coberto pelos testes de falha intermediária existentes.

## Limitações e necessidades para produção

- O escopo é uma simulação local com depósitos manuais. As decisões de liquidação, integração bancária e autenticação de provedores dependem de requisitos futuros.
- O `.env.example` usa `APP_ENV=local` e `APP_DEBUG=true`. Uma implantação pública exigiria HTTPS, configuração de cookies, debug desativado, gestão de segredos, permissões mínimas e observabilidade apropriada.
- Cadastro possui validação e unicidade de e-mail. Um limitador específico de cadastro e mecanismos adicionais contra abuso permanecem fora da implementação atual.
- O usuário SQL de desenvolvimento possui permissões de manutenção. Restrições de privilégio, backups, restauração e proteção operacional do histórico precisam de desenho de produção.
- O log financeiro inesperado limita o diagnóstico. Métricas, correlação e tratamento de incidentes devem preservar a sanitização existente.
- A conciliação desta revisão é uma consulta pontual de leitura. Operação contínua e procedimento para investigar divergências dependem de requisitos posteriores.
- O serviço pressupõe histórico consistente. Alterações SQL diretas, concorrência entre processos e cenários de falha de infraestrutura exigem verificações adicionais conforme a implantação.
- Lockfiles fixam dependências de aplicação. Imagens por tag e downloads do Dockerfile Sail impedem garantir um ambiente bit a bit idêntico no futuro.

## Evidências da auditoria final

### Resultados das verificações

| Verificação | Resultado observado em 11/11/2026 |
| --- | --- |
| Runtime | PHP 8.3.36 de 64 bits, Laravel 12.72.0, MySQL 8.4.13/InnoDB, Node 24.22.2 e npm 12.2.2. |
| PHPUnit no ambiente existente | 185 testes aprovados, 1.248 asserções, usando exclusivamente `testing`. A suíte anterior à revisão possuía 177 testes aprovados. |
| PHPUnit na instalação isolada | 185 testes aprovados, 1.248 asserções, no banco `testing` do MySQL isolado. |
| Laravel Pint | Preset PSR-12 aprovado em 63 arquivos PHP. |
| Vite | `npm run build` aprovado no ambiente existente e após `npm ci` na cópia isolada, com manifest, CSS e JavaScript gerados. |
| Composer | `composer validate --strict` aprovado. `composer audit --locked` retornou zero advisories e zero pacotes abandonados na consulta realizada. |
| npm | `npm audit` retornou zero vulnerabilidades na consulta realizada. |
| Rotas | `route:list -v` apresentou 13 rotas, com autenticação e throttle nas ações financeiras e middleware web nas rotas da aplicação. |
| Migrations | As seis migrations constavam como executadas no desenvolvimento. Todas foram aplicadas em bancos novos da instalação isolada. |
| Docker Compose | `config --quiet` aprovado. Desenvolvimento com os três serviços ativos e MySQL saudável. |
| Carregamento HTTP | Home, login, cadastro, healthcheck e os assets compilados retornaram HTTP 200 nos dois ambientes. |
| Smoke financeiro isolado | Dois cadastros, depósito de R$ 10,50, transferência de R$ 2,00 e reversão da transferência aprovados por HTTP com sessão e CSRF reais. |
| Git e entrega | Branch `main`, histórico íntegro em `git fsck --full`, lockfiles e `.env.example` versionados. A inspeção de arquivos rastreados e padrões comuns de segredo ficou sem achados. |

O Composer do container existente emitiu avisos de ownership do Git ao determinar metadados do pacote raiz, mas a validação e a auditoria concluíram com sucesso. A instalação isolada concluiu o Composer normalmente. O npm da instalação isolada informou bloqueio do postinstall opcional de `esbuild` pela política padrão de scripts dessa versão. O build foi concluído usando o binário disponibilizado pelo pacote de plataforma.

### Conferência financeira

| Requisito inspecionado | Evidência na implementação e na suíte |
| --- | --- |
| Depósitos próprios e saldo suficiente em transferências | Origem derivada do ator, propriedade conferida sob lock e rejeição integral de transferência sem saldo. |
| Autorização e extrato individual | Sessão autenticada nas rotas, verificação do iniciador na reversão e consulta do extrato pela relação da carteira autenticada. |
| Idempotência e colisões | Comparação canônica, UNIQUE global, rollback da tentativa perdedora e recuperação limitada por leitura atual. |
| FOR UPDATE e ordenação | Carteiras consultadas individualmente em ordem crescente de PK. Reversão bloqueia a original antes das carteiras. |
| Transações e rollback | Operação, lançamentos, saldos e status da original na mesma transação, com testes de falhas intermediárias. |
| Reversão compensatória e única | Nova operação vinculada à original por referência UNIQUE. Valores e lançamentos originais preservados. |
| Saldo negativo e depósito subsequente | Reversões permitem negativo dentro de BIGINT e depósitos somam ao saldo existente. |
| Overflow, underflow e precisão | BIGINT SIGNED, casts inteiros, conversão textual e checagem prévia das somas. Testes dos dois extremos de 64 bits. |

### Reconciliação somente de leitura

A consulta abaixo foi executada no banco de desenvolvimento em transação READ ONLY. A comparação usa os valores inteiros retornados pelo MySQL como texto, preservando a precisão. A ausência de lançamentos é tratada como zero.

```sql
START TRANSACTION READ ONLY;

SELECT w.id,
       w.balance_cents,
       COALESCE(l.ledger_total, 0) AS ledger_total
FROM wallets AS w
LEFT JOIN (
    SELECT wallet_id, SUM(delta_cents) AS ledger_total
    FROM ledger_entries
    GROUP BY wallet_id
) AS l ON l.wallet_id = w.id
ORDER BY w.id;

ROLLBACK;
```

Foram analisadas **duas carteiras**, com **zero divergências** e lista vazia de identificadores divergentes. A verificação foi repetida após os testes, com o mesmo resultado. O banco de desenvolvimento continha cinco operações e oito lançamentos nas duas verificações. Os testes e o smoke da instalação utilizaram bancos separados.

### Instalação limpa e limites da reprodução

A instalação começou em cópia temporária dos arquivos rastreados, acrescida das correções de código da etapa 05, sem `vendor`, `node_modules` ou `.env` copiados do ambiente existente. Foram usados ambiente e credenciais novos, projeto Compose `wallet-php-etapa05-audit`, banco `wallet_audit`, rede própria e volume `wallet-php-etapa05-audit_sail-mysql`. As portas foram `18088`, `18089` e `13309`, vinculadas a loopback. Um override temporário substituiu a porta fixa do phpMyAdmin e atribuiu nome próprio à imagem da aplicação.

O bootstrap seguiu o README com Composer local sem scripts, instalação posterior no container, geração de APP_KEY, migrations, `npm ci` e build. O script Sail criou o banco `testing` no volume novo. Após aplicar suas migrations, a suíte completa passou. O smoke HTTP financeiro utilizou somente `wallet_audit`.

O build Docker reutilizou camadas locais. Foram verificadas instalação funcional e resolução dos lockfiles nesse host, com acesso aos fornecedores de pacotes. Uma máquina sem cache, outro sistema operacional e futuras versões das tags de imagens ainda precisam de validação própria. A primeira tentativa do Composer foi bloqueada por permissões do sandbox e a repetição com as permissões necessárias concluiu o bootstrap.

Após a verificação, os containers e a rede isolados foram encerrados e removidos. A cópia temporária foi removida. O volume MySQL novo foi mantido, conforme a restrição sobre exclusão de volumes, e a imagem isolada permanece no Docker local.

### Documentação para avaliação

O README agora explicita requisitos, bootstrap, banco de testes, build, uso dos formulários e links internos para os três documentos técnicos. A referência externa ao enunciado já havia sido retirada pelo commit `b19ff0a`. A revisão conferiu os links locais e manteve as instruções autocontidas.

## Referências técnicas

A revisão usou as categorias de autorização, configuração, cadeia de dependências, criptografia, injeção, desenho, autenticação, integridade, logs e exceções do OWASP Top 10 de 2025 como guia. [OWASP Top 10 2025](https://owasp.org/Top10/2025/).

O limite de 72 bytes do bcrypt está documentado no manual PHP. O comportamento de NUL foi reproduzido no PHP 8.3.36 desta aplicação e pelos testes de regressão. [Manual de password_hash](https://www.php.net/manual/en/function.password-hash.php).
