# Regras de negócio da carteira

Contrato para a simulação financeira. Cadastro, sessão e dashboard foram implementados na etapa 03. A etapa 04A implementa as regras de movimentação e autorização em `WalletService`, exercitado pelos testes. Ainda não existem rotas, controllers, Form Requests ou interfaces de movimentação financeira.

## RN01 — Usuário possui carteira individual

Cada usuário deverá possuir uma carteira exclusiva. A FK única `wallets.user_id` garante no máximo uma; o cadastro da etapa 03 cria usuário e carteira com saldo zero na mesma transação. Falha na criação da carteira desfaz o usuário. Não há carteira compartilhada nem troca de proprietário prevista. Usuários antigos criados fora desse fluxo não recebem carteiras automaticamente.

## RN02 — Depósitos exigem valor positivo

Aceitar apenas valor inteiro em centavos maior que zero, dentro do limite suportado. O banco rejeita zero e valores negativos em `operations.amount_cents`. Depósito tem origem nula, uma carteira de destino e um lançamento positivo igual ao valor da operação.

## RN03 — Depósitos podem corrigir saldo negativo

Somar o depósito ao saldo atual, inclusive negativo. Saldo −500 mais depósito de 300 resulta em −200 centavos; −500 mais 800 resulta em 300. Não substituir nem descartar o saldo anterior.

## RN04 — Transferência exige saldo suficiente

O saldo da origem deverá ser maior ou igual ao valor no instante da escrita. `WalletService` lê o saldo após `lockForUpdate()`, dentro de `DB::transaction()`. Saldo negativo ou insuficiente impede toda a transferência, sem débito parcial.

## RN05 — Origem e destino devem ser diferentes

Transferência exige duas carteiras existentes e distintas. O banco verifica existência e distinção; o serviço valida participantes e autorização. A origem vem do ator; o destino informado é o ID da carteira de outro usuário. Nenhuma movimentação tem valor zero.

## RN06 — Operações devem ser atômicas

Operação, lançamentos e saldos envolvidos são confirmados em uma única transação SQL. Reversão inclui o status da original. Qualquer falha reverte todas essas alterações. Não separar os lados de uma transferência em transações diferentes. Se o serviço for chamado dentro de uma transação externa, o commit final e a responsabilidade por essa unidade maior pertencem ao chamador.

## RN07 — Operações devem possuir histórico

Cada movimentação confirmada registra iniciador, tipo, status, valor, participantes, chave idempotente, timestamps e lançamentos. Débitos são negativos; créditos são positivos. O serviço preserva a igualdade entre saldo e soma dos lançamentos a partir de histórico consistente e cadastro com saldo zero. Não corrige automaticamente divergências produzidas por SQL externo.

Não editar ou excluir operações e lançamentos confirmados. FKs restritivas protegem pais referenciados, mas não tornam folhas imutáveis contra SQL direto. O serviço não fornece edição/exclusão de histórico; a única alteração permitida na original é seu status durante uma reversão.

## RN08 — Operações confirmadas podem ser revertidas

Depósitos e transferências `completed` poderão ser revertidos pelo ator autorizado. Criar nova operação `reversal`, `completed`, com o valor original e lançamentos de sinais opostos.

- Depósito: destino original passa a ser a origem da reversão; destino nulo; débito compensatório.
- Transferência: inverter origem e destino; debitar quem recebeu e creditar quem enviou.

Inconsistência antes do commit exige rollback, sem reversão de operação inexistente. Inconsistência após commit segue o mesmo fluxo autorizado de reversão. Não haverá ator de sistema ou endpoint público que ignore autorização; automação de incidentes exigirá definição posterior de identidade e permissões.

## RN09 — Reversões não podem ser duplicadas

Uma original terá no máximo uma reversão confirmada. `reversal_of_operation_id` é nullable e `UNIQUE`: múltiplas operações comuns aceitam `NULL`, mas o mesmo identificador original não pode aparecer em duas reversões. O serviço bloqueia a original e verifica seu estado antes de escrever. Reversões não podem ser alvo de outra reversão.

## RN10 — Reversões preservam a operação original

A original muda de `completed` para `reversed`, sem modificar iniciador, valor, tipo, participantes, chave ou lançamentos. A nova operação aponta à original e registra seu solicitante. Nada é apagado. `updated_at` registra a mudança de estado; a reversão tem timestamps próprios.

## RN11 — Solicitações repetidas não podem duplicar movimentações

Cada solicitação recebe `idempotency_key` não vazia, globalmente única, com até 128 caracteres. O serviço rejeita UTF-8 inválido, whitespace nas extremidades e controles ASCII. UUID canônico é recomendado. A chave é sensível a maiúsculas/minúsculas e o texto exato é comparado no replay, respeitando as limitações de collation documentadas na arquitetura.

Mesma chave, mesmo iniciador e mesmo conteúdo recuperam a operação já processada sem repetir efeitos. Comparar tipo, valor, carteiras e referência original. Conteúdo diferente gera conflito. Autorizar antes de retornar qualquer registro; colisão com chave de outro usuário deve produzir resposta genérica, sem revelar dados.

Reversões usam uma nova chave. Repetir essa chave com mesmo conteúdo recupera a mesma reversão; outra chave tentando reverter a mesma original é rejeitada pela RN09. Repetir a chave de um depósito/transferência já `reversed` retorna a operação original no estado registrado, sem reaplicar valores. A transação SQL e o `UNIQUE` atuam juntos; erro de unicidade é tratado somente após rollback. Uma nova leitura bloqueada recupera e compara a vencedora; duplicidade de lançamento não é tratada como sucesso idempotente.

## RN12 — Usuários não podem movimentar carteiras de terceiros indevidamente

O futuro controller deve obter o ator da sessão autenticada, nunca de `initiated_by_user_id` recebido do cliente. O serviço recebe esse `User` confiável e já aplica:

- Depósito somente na própria carteira nesta simulação.
- Transferência somente a partir da própria carteira; destino pode ser outro usuário existente.
- Leitura apenas do próprio saldo/extrato; informações do outro participante devem ser limitadas ao necessário.
- Reversão somente pelo usuário que iniciou a operação original. Ele também deverá ser proprietário da origem original em transferências, ou do destino original em depósitos.

O destinatário não pode reverter uma transferência apenas por ter recebido crédito. O efeito compensatório sobre a carteira alheia é autorizado pela operação original, sem confiar em IDs/valores enviados pelo cliente. Não existe papel administrativo ou exceção implícita. O serviço recupera os participantes e revalida propriedade dentro da transação. Original inexistente e original de outro iniciador geram a mesma rejeição de autorização, sem revelar seus dados.

## RN13 — Reversões podem produzir saldo negativo

Na simulação, uma reversão autorizada e não realizada não depende de saldo suficiente de quem devolverá o valor. Ela deve desfazer a original mesmo se o destinatário já utilizou o crédito.

Exemplo: transferência original de 1.000 centavos; destinatário possui apenas 200 no momento da reversão. O débito de 1.000 deixa saldo −800. Transferências comuns continuam sujeitas à RN04; depósitos futuros somam ao negativo pela RN03. Limites de `BIGINT` continuam válidos nas reversões.

## RN14 — Valores financeiros são representados em centavos inteiros

R$ 10,50 corresponde a 1050 centavos. `balance_cents`, `amount_cents` e `delta_cents` usam `BIGINT` assinado. O valor da operação é positivo; o sinal financeiro fica nos lançamentos. Não usar `FLOAT`, `DOUBLE`, arredondamento implícito ou conversão por ponto flutuante. O dashboard formata inteiros como texto; o serviço exige um `int` real e positivo, rejeitando inclusive strings numéricas e floats. A camada HTTP futura deverá validar/converter sua representação de entrada sem perda. Somas verificam overflow/underflow antes de calcular o resultado.

## Ciclo de vida

| Registro | Estado inicial confirmado | Transição permitida |
| --- | --- | --- |
| Depósito ou transferência | `completed` | `reversed`, junto ao commit da reversão |
| Reversão | `completed` | Nenhuma |

`reversed` é terminal. Não há estado pendente nem operação falha persistida neste esquema: falhas desfazem a transação. O banco verifica estados possíveis e o serviço coordena a sequência. A original e seus lançamentos são consultados no servidor e verificados antes de aplicar compensações.

## Transações, concorrência e limitações

O serviço usa `DB::transaction()` e `lockForUpdate()`. Carteiras são bloqueadas por PK em ordem crescente; em reversões, primeiro bloquear a original e depois as carteiras. Estado, autorização e idempotência são verificados na transação. O Laravel pode repetir a unidade até três vezes em erros de concorrência suportados. A colisão idempotente admite uma tentativa de recuperação somente após rollback.

Os testes executam movimentações, falhas intermediárias, reversões, unicidade e extremos numéricos no MySQL `testing`, sem reset. A simulação de leitura inicial vazia exerce o caminho SQL de colisão/rollback/replay, mas é sequencial; não comprova a segurança de todos os cenários concorrentes. Testes futuros de concorrência deverão usar processos/conexões independentes. Não há conciliação/reparação automática de dados alterados fora do serviço.
