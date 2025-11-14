# Deploy Rápido - eSocial Service no Heroku

## Solução Rápida (1 comando)

Execute dentro da pasta `esocial-service`:

```bash
cd esocial-service
HEROKU_APP_NAME=seu-app-esocial-service ./deploy-heroku.sh
```

Ou se preferir, o script pedirá o nome do app:

```bash
cd esocial-service
./deploy-heroku.sh
```

## O que o script faz:

1. ✅ Verifica se está na pasta correta
2. ✅ Inicializa Git (se necessário)
3. ✅ Cria/configura app no Heroku
4. ✅ Configura buildpack PHP
5. ✅ Faz o deploy

## Pré-requisitos:

- Heroku CLI instalado: https://devcenter.heroku.com/articles/heroku-cli
- Logado no Heroku: `heroku login`

## Após o deploy:

1. Configure no seu app Node.js:
   ```bash
   heroku config:set ESOCIAL_SERVICE_URL=https://seu-app-esocial-service.herokuapp.com -a seu-app-nodejs
   ```

2. Teste o serviço:
   ```bash
   curl https://seu-app-esocial-service.herokuapp.com/health
   ```

