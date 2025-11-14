# Deploy do eSocial Service no Heroku

Este guia explica como fazer deploy do serviço PHP eSocial no Heroku usando a **CLI do Heroku**.

> 💡 **Prefere usar o Dashboard?** Veja o guia [DEPLOY_HEROKU_DASHBOARD.md](./DEPLOY_HEROKU_DASHBOARD.md) para instruções passo a passo usando apenas a interface web.

## Pré-requisitos

1. Conta no Heroku
2. Heroku CLI instalado
3. Git configurado

## Opção 1: App Separado (Recomendado)

O eSocial Service deve ser deployado como um **app separado** no Heroku, pois precisa de um buildpack PHP.

### Passo 1: Criar o App no Heroku

```bash
cd esocial-service
heroku create seu-app-esocial-service
```

### Passo 2: Configurar Buildpack PHP

```bash
heroku buildpacks:set heroku/php -a seu-app-esocial-service
```

### Passo 3: Configurar Variáveis de Ambiente (se necessário)

```bash
heroku config:set APP_DEBUG=false -a seu-app-esocial-service
```

### Passo 4: Fazer Deploy

```bash
git init  # Se ainda não tiver
git add .
git commit -m "Deploy eSocial Service"
git push heroku main
```

### Passo 5: Obter a URL do Serviço

Após o deploy, você receberá uma URL como:
```
https://seu-app-esocial-service.herokuapp.com
```

### Passo 6: Configurar no Backend Node.js

No seu app Node.js no Heroku, configure a variável de ambiente:

```bash
heroku config:set ESOCIAL_SERVICE_URL=https://seu-app-esocial-service.herokuapp.com -a seu-app-nodejs
```

Ou adicione no arquivo `.env` do seu backend:
```env
ESOCIAL_SERVICE_URL=https://seu-app-esocial-service.herokuapp.com
```

## Opção 2: Mesmo App (Não Recomendado)

Se você quiser tentar rodar PHP e Node.js no mesmo app (mais complexo):

1. Use buildpacks múltiplos
2. Configure o Procfile para rodar ambos
3. Mais difícil de manter e debugar

## Verificação

Após o deploy, teste o serviço:

```bash
curl https://seu-app-esocial-service.herokuapp.com/health
```

Deve retornar:
```json
{
  "status": "ok",
  "service": "esocial-api"
}
```

## Configuração do Certificado

O certificado digital deve ser configurado através da API após o deploy:

```bash
curl -X POST https://seu-app-esocial-service.herokuapp.com/config \
  -H "Content-Type: application/json" \
  -d '{
    "tpAmb": 2,
    "verProc": "SISTEMA-RH-1.0",
    "empregador": {
      "tpInsc": 1,
      "nrInsc": "12345678000190"
    },
    "certificate": {
      "pfx": "base64_encoded_certificate",
      "password": "senha_do_certificado"
    }
  }'
```

## Troubleshooting

### Erro: "Composer autoload não encontrado"
- Certifique-se de que o `vendor/` está commitado OU
- O Heroku executará `composer install` automaticamente

### Erro: "500 Internal Server Error"
- Verifique os logs: `heroku logs --tail -a seu-app-esocial-service`
- Verifique se todas as extensões PHP necessárias estão disponíveis

### Erro de CORS
- O `.htaccess` já está configurado para permitir CORS
- Verifique se o header está sendo enviado corretamente

## Notas Importantes

1. **Persistência de Dados**: O Heroku tem sistema de arquivos efêmero. A configuração salva em `config.json` será perdida em cada deploy. Considere usar:
   - Variáveis de ambiente do Heroku
   - Banco de dados para armazenar configurações
   - Serviço de storage externo (S3, etc.)

2. **Certificado Digital**: O certificado deve ser reconfigurado após cada deploy se estiver usando arquivo. Considere armazenar em variável de ambiente.

3. **Logs**: Use `heroku logs --tail -a seu-app-esocial-service` para ver logs em tempo real.

