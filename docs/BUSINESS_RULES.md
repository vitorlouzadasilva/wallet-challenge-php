# Regras de negócio da carteira

Contrato para a simulação financeira. Cadastro com carteira inicial, sessão autenticada e consulta do próprio saldo foram implementados na etapa 03. As regras de movimentação continuam planejadas; nenhuma API financeira, autorização de movimentações ou WalletService está implementada.

## RN01 — Usuário possui carteira individual

Cada usuário deverá possuir uma carteira exclusiva. A FK única `wallets.user_id` garante no máximo uma; o cadastro da etapa 03 cria usuário e carteira com saldo zero na mesma transação. Falha na criação da carteira desfaz o usuário. Não há carteira compartilhada nem troca de proprietário prevista. Usuários antigos criados fora desse fluxo não recebem carteiras automaticamente.

## RN02 — Depósitos exigem valor positivo

Aceitar apenas valor inteiro em centavos maior que zero, dentro do limite suportado. O banco rejeita zero e valores negativos em `operations.amount_cents`. Depósito tem origem nula, uma carteira de destino e um lançamento positivo igual ao valor da operação.

## RN03 — Depósitos podem corrigir saldo negativo

Somar o depósito ao saldo atual, inclusive negativo. Saldo −500 mais depósito de 300 resulta em −200 centavos; −500 mais 800 resulta em 300. Não substituir nem descartar o saldo anterior.

## RN04 — Transferência exige saldo suficiente

O saldo da origem deverá ser maior ou igual ao valor no instante da escrita. Ler o saldo após `lockForUpdate()`, dentro de `DB::transaction()`. Saldo negativo ou insuficiente impede toda a transferência, sem débito parcial.

## RN05 — Origem e destino devem ser diferentes

Transferência exige duas carteiras existentes e distintas. O banco verifica existência e distinção; o futuro serviço validará os participantes e a autorização. Nenhuma movimentação tem valor zero.

## RN06 — Operações devem ser atômicas

Operação, lançamentos e saldos envolvidos serão confirmados em um único commit. Reversão inclui o status da original. Qualquer falha reverte todas essas alterações. Não separar os lados de uma transferência em transações diferentes.

## RN07 — Operações devem possuir histórico

Cada movimentação confirmada registra iniciador, tipo, status, valor, participantes, chave idempotente, timestamps e lançamentos. Débitos são negativos; créditos são positivos. O saldo materializado deverá refletir a soma dos lançamentos, iniciando em zero.

Não editar ou excluir operações e lançamentos confirmados pelos futuros fluxos. FKs restritivas protegem pais referenciados, mas não tornam folhas imutáveis contra SQL direto; serviço e controles de acesso preservarão a auditoria.

## RN08 — Operações confirmadas podem ser revertidas

Depósitos e transferências `completed` poderão ser revertidos pelo ator autorizado. Criar nova operação `reversal`, `completed`, com o valor original e lançamentos de sinais opostos.

- Depósito: destino original passa a ser a origem da reversão; destino nulo; débito compensatório.
- Transferência: inverter origem e destino; debitar quem recebeu e creditar quem enviou.

Inconsistência antes do commit exige rollback, sem reversão de operação inexistente. Inconsistência após commit segue o mesmo fluxo autorizado de reversão. Não haverá ator de sistema ou endpoint público que ignore autorização; automação de incidentes exigirá definição posterior de identidade e permissões.

## RN09 — Reversões não podem ser duplicadas

Uma original terá no máximo uma reversão confirmada. `reversal_of_operation_id` é nullable e `UNIQUE`: múltiplas operações comuns aceitam `NULL`, mas o mesmo identificador original não pode aparecer em duas reversões. O serviço bloqueará a original e verificará seu estado antes de escrever. Reversões não podem ser alvo de outra reversão.

## RN10 — Reversões preservam a operação original

A original muda de `completed` para `reversed`, sem modificar iniciador, valor, tipo, participantes, chave ou lançamentos. A nova operação aponta à original e registra seu solicitante. Nada é apagado. `updated_at` registra a mudança de estado; a reversão tem timestamps próprios.

## RN11 — Solicitações repetidas não podem duplicar movimentações

Cada solicitação terá `idempotency_key` não vazia, globalmente única, com até 128 caracteres. O futuro contrato rejeitará espaços nas extremidades; UUID canônico é recomendado. A chave é sensível a maiúsculas/minúsculas, respeitando a comparação MySQL documentada na arquitetura.

Mesma chave, mesmo iniciador e mesmo conteúdo recuperam a operação já processada sem repetir efeitos. Comparar tipo, valor, carteiras e referência original. Conteúdo diferente gera conflito. Autorizar antes de retornar qualquer registro; colisão com chave de outro usuário deve produzir resposta genérica, sem revelar dados.

Reversões usam uma nova chave. Repetir essa chave com mesmo conteúdo recupera a mesma reversão; outra chave tentando reverter a mesma original é rejeitada pela RN09. A transação SQL e o `UNIQUE` atuarão juntos sob concorrência; erro de unicidade não autoriza reaplicar efeitos.

## RN12 — Usuários não podem movimentar carteiras de terceiros indevidamente

Derivar o ator da sessão autenticada, nunca de `initiated_by_user_id` recebido do cliente. Futuras Policies/Gates e o serviço aplicarão:

- Depósito somente na própria carteira nesta simulação.
- Transferência somente a partir da própria carteira; destino pode ser outro usuário existente.
- Leitura apenas do próprio saldo/extrato; informações do outro participante devem ser limitadas ao necessário.
- Reversão somente pelo usuário que iniciou a operação original. Ele também deverá ser proprietário da origem original em transferências, ou do destino original em depósitos.

O destinatário não pode reverter uma transferência apenas por ter recebido crédito. O efeito compensatório sobre a carteira alheia é autorizado pela operação original, sem confiar em IDs/valores enviados pelo cliente. Não existe papel administrativo ou exceção implícita. Recuperar os participantes no servidor e revalidar autorização dentro da transação, evitando enumeração de operações entre usuários.

## RN13 — Reversões podem produzir saldo negativo

Na simulação, uma reversão autorizada e não realizada não depende de saldo suficiente de quem devolverá o valor. Ela deve desfazer a original mesmo se o destinatário já utilizou o crédito.

Exemplo: transferência original de 1.000 centavos; destinatário possui apenas 200 no momento da reversão. O débito de 1.000 deixa saldo −800. Transferências comuns continuam sujeitas à RN04; depósitos futuros somam ao negativo pela RN03. Limites de `BIGINT` continuam válidos nas reversões.

## RN14 — Valores financeiros são representados em centavos inteiros

R$ 10,50 corresponde a 1050 centavos. `balance_cents`, `amount_cents` e `delta_cents` usam `BIGINT` assinado. O valor da operação é positivo; o sinal financeiro fica nos lançamentos. Não usar `FLOAT`, `DOUBLE`, arredondamento implícito ou conversão por ponto flutuante. O dashboard da etapa 03 formata o saldo usando inteiros e texto; a entrada de valores financeiros será definida com o serviço. Validar limites para evitar overflow.

## Ciclo de vida

| Registro | Estado inicial confirmado | Transição permitida |
| --- | --- | --- |
| Depósito ou transferência | `completed` | `reversed`, junto ao commit da reversão |
| Reversão | `completed` | Nenhuma |

`reversed` é terminal. Não há estado pendente nem operação falha persistida neste esquema: falhas desfazem a transação. O banco verifica estados possíveis, mas a sequência depende do futuro serviço.

## Transações, concorrência e limitações

Usar `DB::transaction()` e bloqueios de linha `lockForUpdate()` quando necessários. Bloquear carteiras em ordem crescente de identificador; em reversões, primeiro bloquear a original e depois as carteiras. Consultar estado, autorização e idempotência dentro da transação. Deadlocks terão repetição limitada e segura de toda a unidade transacional.

Os testes atuais validam esquema, cadastro transacional e segurança de sessão; não executam movimentações nem comprovam garantias concorrentes financeiras. Conciliação, número/sinais dos lançamentos, correspondência de reversões, idempotência de respostas e autorização financeira exigirão testes de domínio e integração na etapa seguinte. Revisar as decisões antes de implementar os serviços financeiros.
