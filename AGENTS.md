# Diretrizes do projeto

- Usar PHP 8.3 e Laravel 12, com Composer e autoload PSR-4.
- Seguir MVC com camada de serviços para regras de negócio quando forem implementadas. Manter controllers enxutos.
- Aplicar SOLID e design patterns quando resolverem uma necessidade concreta; não adicionar abstrações desnecessárias.
- Seguir PSR-12, validado pelo Laravel Pint com o preset definido em `pint.json`.
- Escrever métodos com tipos claros nos parâmetros e retornos, respeitando os contratos do framework.
- Escrever comentários em português para regras de negócio e decisões arquiteturais relevantes. Evitar comentários redundantes.
- Manter código simples e compreensível e não implementar funções não solicitadas.
- Justificar dependências adicionais antes de incluí-las.
- Guiar decisões de segurança pelo [OWASP Top 10:2025](https://owasp.org/Top10/2025/): autorização, configuração segura, dependências, criptografia, prevenção de injeção, desenho seguro, autenticação, integridade, logs e tratamento de exceções.
- Nunca versionar `.env`, credenciais ou segredos. Usar credenciais exclusivas de desenvolvimento.
- Criar testes unitários e de integração para regras financeiras críticas quando essas regras forem autorizadas e implementadas. Cobrir saldo, precisão monetária, atomicidade, concorrência e reversões conforme os requisitos aprovados.
- Executar PHP, Artisan, Composer, PHPUnit e Pint preferencialmente dentro do container `laravel.test` após o bootstrap.
- Usar o runtime oficial PHP 8.3 do Laravel Sail e os serviços `laravel.test`, `mysql` e `phpmyadmin`. Manter o phpMyAdmin restrito a `127.0.0.1:8089`, sem login automático.
- Não executar `docker compose down -v`, `migrate:fresh`, exclusão de volumes ou outros comandos destrutivos sem autorização explícita.
- Não sobrescrever nem remover arquivos preexistentes do usuário sem autorização.
- A etapa 03 autoriza cadastro transacional com carteira inicial, autenticação por sessão e interface inicial em Blade/Bootstrap 5. Aguardar revisão antes de implementar controllers financeiros, WalletService financeiro, depósitos, transferências, reversões, recuperação de senha e painel administrativo.
