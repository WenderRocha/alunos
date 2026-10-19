# Deploy no Coolify

Este projeto pode ser publicado no Coolify usando o `Dockerfile` da raiz. A imagem final usa PHP 8.4, expõe a porta `80`, executa Nginx e PHP-FPM no mesmo container e possui healthcheck em `/up`.

O scheduler de cobranças não deve ser executado manualmente dentro do Nginx. Configure um Scheduled Task do Coolify para chamar `php artisan schedule:run` a cada minuto; o Laravel decide quando executar `billing:process` conforme `BILLING_PROCESS_TIME`.

## Arquivos de infraestrutura

- `Dockerfile`: imagem de produção em múltiplos estágios, com Composer, Node/Vite, PHP-FPM, Nginx e Supervisor.
- `docker/`: entrypoint, configuração do Nginx, PHP e Supervisor.
- `compose.yaml`: ambiente opcional para testar localmente com PostgreSQL, Redis, aplicação e scheduler.
- `.dockerignore`: evita enviar `.env`, dependências locais e caches para o build.

## 1. Pré-requisitos

Você precisa ter:

1. Um servidor Coolify funcional.
2. Um repositório Git com este projeto.
3. Um banco PostgreSQL ou MySQL acessível pela aplicação.
4. Um Redis acessível pela aplicação, recomendado para sessão e cache.
5. Uma instância Evolution API acessível pelo servidor Coolify.

No Coolify, é possível criar PostgreSQL e Redis como recursos separados ou utilizar serviços externos. Use os hostnames internos fornecidos pelo próprio Coolify, não `127.0.0.1`.

## 2. Criar a aplicação

No Coolify:

1. Crie ou abra um Project e um Environment.
2. Adicione uma Application a partir do repositório Git.
3. Selecione a branch de produção.
4. Em Build Pack, selecione `Dockerfile`.
5. Use `/` como Base Directory.
6. Use `/Dockerfile` como Dockerfile Location.
7. Em Ports Exposes, informe `80`.
8. Adicione o domínio público da aplicação.
9. Salve e faça o primeiro Deploy somente depois de configurar as variáveis abaixo.

O Coolify documenta esse fluxo em [Dockerfile Applications](https://coolify.io/docs/applications/builds/dockerfile). O processo do container precisa escutar em `0.0.0.0`; nesta configuração isso já é atendido pelo Nginx na porta `80`.

## 3. Variáveis de ambiente

Configure as variáveis na tela **Environment Variables** da aplicação. Nunca coloque valores reais no `Dockerfile`, no Git ou em um arquivo `.env` versionado.

### Aplicação

```env
APP_NAME=Alunos
APP_ENV=production
APP_KEY=base64:COLOQUE_UMA_CHAVE_UNICA_AQUI
APP_DEBUG=false
APP_URL=https://alunos.seudominio.com.br
APP_LOCALE=pt_BR
APP_FALLBACK_LOCALE=en

LOG_CHANNEL=stderr
LOG_STACK=stderr
LOG_LEVEL=info

RUN_MIGRATIONS=true
RUN_OPTIMIZE=true
```

Gere a chave com:

```bash
php artisan key:generate --show
```

Se não tiver PHP instalado localmente:

```bash
php -r 'echo "base64:" . base64_encode(random_bytes(32)) . PHP_EOL;'
```

### Banco PostgreSQL recomendado

Use os dados internos do recurso PostgreSQL criado no Coolify:

```env
DB_CONNECTION=pgsql
DB_HOST=HOST_INTERNO_DO_POSTGRES
DB_PORT=5432
DB_DATABASE=alunos
DB_USERNAME=alunos
DB_PASSWORD=SENHA_DO_POSTGRES
```

Para MySQL, altere `DB_CONNECTION` para `mysql` e informe a porta e as credenciais correspondentes. A imagem já inclui os drivers PostgreSQL e MySQL.

### Sessão, cache e filas

```env
SESSION_DRIVER=redis
SESSION_LIFETIME=120
SESSION_SECURE_COOKIE=true

CACHE_STORE=redis
QUEUE_CONNECTION=sync

REDIS_CLIENT=phpredis
REDIS_HOST=HOST_INTERNO_DO_REDIS
REDIS_PASSWORD=SENHA_DO_REDIS_OU_null
REDIS_PORT=6379
```

O projeto atualmente processa as cobranças de forma síncrona, por isso `QUEUE_CONNECTION=sync` não exige um worker separado. Se uma fila for adotada no futuro, crie um worker dedicado no Coolify.

### Evolution API e cobranças

```env
EVOLUTION_API_URL=https://evolution.seudominio.com.br
EVOLUTION_API_KEY=SUA_CHAVE_DA_EVOLUTION
EVOLUTION_INSTANCE=nome-da-instancia
EVOLUTION_API_TIMEOUT=15
EVOLUTION_TEXT_PAYLOAD_FORMAT=top-level
EVOLUTION_DEFAULT_COUNTRY_CODE=55

BILLING_REMINDER_DAYS=7,3,1
BILLING_PROCESS_TIME=08:00
```

O horário da aplicação está configurado para `America/Sao_Paulo`. Confirme que a URL da Evolution, a chave e o nome da instância estão corretos e que a instância está conectada ao WhatsApp.

## 4. Migrações

Para o primeiro deploy com uma única réplica, mantenha:

```env
RUN_MIGRATIONS=true
```

O entrypoint executará `php artisan migrate --force` antes de iniciar o web server.

Em ambientes com mais de uma réplica, prefira deixar `RUN_MIGRATIONS=false` e configurar um comando de pós-deploy do Coolify:

```bash
php artisan migrate --force
```

Depois do deploy, confira pelo Terminal do container:

```bash
php artisan migrate:status
php artisan about
```

## 5. Scheduler das cobranças

Abra **Scheduled Tasks** na Application e crie uma tarefa com:

| Campo | Valor |
|---|---|
| Name | `Laravel scheduler` |
| Command | `php artisan schedule:run` |
| Frequency | `* * * * *` |
| Timeout | `300` segundos |
| Container | vazio se houver apenas um container |

Execute a tarefa uma vez manualmente e confira os logs. O comando é chamado a cada minuto, mas a regra registrada no Laravel executa `billing:process` somente no horário configurado em `BILLING_PROCESS_TIME`.

O Coolify executa Scheduled Tasks dentro do container em execução. Consulte [Scheduled Tasks de Applications](https://coolify.io/docs/applications/operations/scheduled-tasks) para criar e verificar a tarefa.

## 6. Healthcheck e logs

O Dockerfile já inclui:

```text
GET http://localhost/up
```

Não é necessário criar outro healthcheck no painel quando o Coolify estiver usando o healthcheck definido no Dockerfile. O endpoint `/up` deve responder com sucesso depois que o PHP-FPM e o Nginx estiverem prontos.

Os logs do Laravel são enviados para `stderr`, portanto devem aparecer em **Runtime Logs** do Coolify. Para investigar a cobrança automática, procure por:

```text
Processamento de cobranças iniciado
Cobrança enviada
Evolution API
Falha ao enviar cobrança
```

O Coolify recomenda que comandos usados no healthcheck, como `curl`, estejam presentes na imagem; esta imagem já instala `curl`. Referência: [Health Checks](https://coolify.io/docs/applications/configuration/health-checks).

## 7. Armazenamento persistente

Se a aplicação passar a armazenar comprovantes ou outros arquivos no disco local, adicione um Persistent Storage no Coolify:

```text
Mount path: /var/www/html/storage/app
```

O banco deve ter backup próprio. O volume de arquivos também deve ser incluído na estratégia de backup. O sistema de arquivos do container é substituível em cada deploy.

## 8. Deploy local opcional

O `compose.yaml` sobe uma aplicação de produção local com PostgreSQL, Redis e scheduler.

```bash
cp .env.example .env
php artisan key:generate
docker compose up -d --build
```

Acesse `http://localhost:8080`. Para acompanhar os logs:

```bash
docker compose logs -f app scheduler
```

Para executar comandos Artisan:

```bash
docker compose exec app php artisan migrate:status
docker compose exec app php artisan about
```

## 9. Checklist pós-deploy

- [ ] `APP_KEY` definida e mantida como segredo.
- [ ] `APP_DEBUG=false`.
- [ ] `APP_URL` aponta para o domínio HTTPS.
- [ ] Banco acessível pelo hostname interno do Coolify.
- [ ] Redis acessível e sessão funcionando após login.
- [ ] Evolution API acessível, com chave e instância corretas.
- [ ] Migrações executadas.
- [ ] Healthcheck `/up` saudável.
- [ ] Scheduled Task `php artisan schedule:run` executando.
- [ ] Teste de cobrança manual realizado.
- [ ] Backup do banco e dos arquivos configurado.
