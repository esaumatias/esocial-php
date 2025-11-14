# Deploy do eSocial Service no Heroku - Via Dashboard

Este guia explica como fazer deploy do serviço PHP eSocial no Heroku usando apenas o **Dashboard Web** (sem CLI).

## Passo 1: Criar o App no Heroku

1. Acesse [https://dashboard.heroku.com](https://dashboard.heroku.com)
2. Faça login na sua conta
3. Clique no botão **"New"** no canto superior direito
4. Selecione **"Create new app"**
5. Preencha:
   - **App name**: `seu-app-esocial-service` (ou outro nome disponível)
   - **Region**: Escolha a região mais próxima (United States ou Europe)
6. Clique em **"Create app"**

## Passo 2: Preparar o Repositório

**⚠️ IMPORTANTE**: O Heroku precisa que o `composer.json` e `index.php` estejam na **raiz** do repositório que será deployado.

Você tem 2 opções:

### Opção A: Repositório Separado (Recomendado)

1. Crie um **novo repositório no GitHub** apenas para o esocial-service
2. Copie todo o conteúdo da pasta `esocial-service/` para a raiz do novo repositório
3. Faça commit e push:
   ```bash
   cd esocial-service
   git init
   git add .
   git commit -m "Initial commit"
   git remote add origin https://github.com/seu-usuario/esocial-service.git
   git push -u origin main
   ```

### Opção B: Usar Subpasta (Requer Configuração)

Se quiser manter tudo no mesmo repositório, você precisa configurar o Heroku para usar a subpasta. Veja a seção "Solução para Subpasta" abaixo.

## Passo 3: Conectar com GitHub

### Se criou repositório separado (Opção A):

1. No dashboard do app, vá para a aba **"Deploy"**
2. Na seção **"Deployment method"**, clique em **"GitHub"**
3. Autorize o Heroku a acessar seu GitHub (se necessário)
4. Procure pelo repositório `esocial-service` e clique em **"Connect"**
5. Selecione o branch (geralmente `main` ou `master`)
6. Ative o **"Automatic deploys"** (opcional)
7. Clique em **"Deploy Branch"** para fazer o primeiro deploy

### Se está usando o repositório principal (Opção B):

Veja a seção "Solução para Subpasta" abaixo.

## Passo 3: Configurar Buildpack PHP

1. No dashboard do app, vá para **"Settings"**
2. Role até a seção **"Buildpacks"**
3. Clique em **"Add buildpack"**
4. Selecione **"heroku/php"** ou cole a URL: `https://github.com/heroku/heroku-buildpack-php`
5. Clique em **"Save changes"**

## Passo 4: Configurar Variáveis de Ambiente

1. No dashboard do app, vá para **"Settings"**
2. Role até a seção **"Config Vars"**
3. Clique em **"Reveal Config Vars"**
4. Adicione as variáveis necessárias (se houver):
   - `APP_DEBUG` = `false` (para produção)
   - Outras variáveis que seu app precise

## Passo 5: Verificar Estrutura do Projeto

Certifique-se de que o diretório `esocial-service` contém:

- ✅ `composer.json` (já existe)
- ✅ `index.php` (já existe)
- ✅ `Procfile` (já criado)
- ✅ `.htaccess` (já existe)
- ✅ Pasta `src/` com o código
- ✅ Pasta `vendor/` (será criada automaticamente pelo Heroku)

**Importante**: Se você estiver fazendo deploy do diretório `esocial-service` diretamente:
- O Heroku executará `composer install` automaticamente
- Não precisa commitar a pasta `vendor/` (adicione ao `.gitignore`)

## Passo 6: Fazer Deploy

### Se conectou com GitHub:

1. Vá para a aba **"Deploy"**
2. Se ativou **"Automatic deploys"**, o deploy acontece automaticamente a cada push
3. Ou clique em **"Deploy Branch"** manualmente
4. Aguarde o build terminar (pode levar alguns minutos)

### Se está usando Heroku Git:

Siga as instruções na tela para instalar o Heroku CLI e fazer push.

## Passo 7: Obter a URL do Serviço

Após o deploy:

1. No dashboard do app, vá para **"Settings"**
2. Na seção **"Domains"**, você verá a URL do app:
   ```
   https://seu-app-esocial-service.herokuapp.com
   ```
3. Copie essa URL

## Passo 8: Configurar no Backend Node.js

1. Acesse o dashboard do seu **app Node.js** no Heroku
2. Vá para **"Settings"**
3. Na seção **"Config Vars"**, clique em **"Reveal Config Vars"**
4. Clique em **"Add"**
5. Adicione:
   - **KEY**: `ESOCIAL_SERVICE_URL`
   - **VALUE**: `https://seu-app-esocial-service.herokuapp.com`
6. Clique em **"Add"**

## Passo 9: Testar o Serviço

1. No dashboard do app esocial-service, vá para **"More"** (menu de 3 pontos)
2. Selecione **"View logs"** para ver os logs em tempo real
3. Teste o endpoint de health:
   - Abra no navegador: `https://seu-app-esocial-service.herokuapp.com/health`
   - Deve retornar:
     ```json
     {
       "status": "ok",
       "service": "esocial-api"
     }
     ```

## Passo 10: Configurar Certificado Digital

Após o deploy, configure o certificado através da interface do seu sistema ou via API:

1. Acesse a interface de configuração do eSocial no seu sistema
2. Preencha:
   - Ambiente (Homologação/Produção)
   - Dados do empregador
   - Certificado digital (PFX em base64)
3. Salve a configuração

## Verificações Importantes

### Verificar se o Deploy Funcionou

1. No dashboard, vá para **"Activity"**
2. Verifique se o último deploy foi bem-sucedido (ícone verde ✓)
3. Se houver erro (ícone vermelho ✗), clique para ver os detalhes

### Verificar Logs

1. No dashboard, clique em **"More"** → **"View logs"**
2. Procure por erros ou mensagens de sucesso
3. Logs comuns:
   - `Composer install` - Instalando dependências
   - `Starting php-fpm` - Servidor iniciando
   - Erros de sintaxe ou dependências faltando

### Verificar Buildpack

1. Vá para **"Settings"** → **"Buildpacks"**
2. Deve aparecer: `heroku/php`
3. Se não aparecer, adicione manualmente (Passo 3)

## Troubleshooting

### Erro: "No app.json found"
- **Solução**: Não é necessário. O Heroku detectará automaticamente que é PHP pelo `composer.json`

### Erro: "Composer install failed"
- **Solução**: Verifique os logs para ver qual dependência falhou
- Verifique se o `composer.json` está correto

### Erro: "500 Internal Server Error"
- **Solução**: 
  1. Verifique os logs: **"More"** → **"View logs"**
  2. Verifique se o `index.php` está na raiz
  3. Verifique se o `.htaccess` está configurado corretamente

### Erro de CORS
- **Solução**: O `.htaccess` já está configurado. Se persistir, verifique os headers nas requisições

### App não inicia
- **Solução**: 
  1. Verifique os logs
  2. Certifique-se de que o `Procfile` está correto
  3. Verifique se o buildpack PHP está configurado

## Dicas Importantes

1. **Sistema de Arquivos Efêmero**: 
   - O Heroku apaga arquivos a cada deploy
   - Não salve configurações em arquivos (use variáveis de ambiente ou banco de dados)

2. **Certificado Digital**:
   - Configure após cada deploy (se não usar variáveis de ambiente)
   - Ou armazene o certificado em base64 como variável de ambiente

3. **Logs**:
   - Use **"View logs"** para debugar problemas
   - Logs são mantidos por 24 horas no plano gratuito

4. **Monitoramento**:
   - Use **"Metrics"** para ver uso de recursos
   - Use **"Activity"** para ver histórico de deploys

## Próximos Passos

Após configurar tudo:

1. ✅ Teste o endpoint `/health`
2. ✅ Configure o certificado digital
3. ✅ Teste envio de um evento simples
4. ✅ Configure no backend Node.js a variável `ESOCIAL_SERVICE_URL`
5. ✅ Teste a integração completa

## Solução para Subpasta (Opção B)

Se você quer manter o `esocial-service` no mesmo repositório do projeto principal, você precisa criar um **repositório separado no GitHub** apenas com o conteúdo de `esocial-service/` na raiz.

### Passos:

1. **Criar novo repositório no GitHub**:
   - Nome: `esocial-service` (ou outro nome)
   - Público ou privado

2. **Clonar e preparar**:
   ```bash
   # Criar diretório temporário
   mkdir esocial-service-deploy
   cd esocial-service-deploy
   
   # Copiar conteúdo de esocial-service para raiz
   cp -r ../esocial-service/* .
   cp -r ../esocial-service/.* . 2>/dev/null || true
   
   # Inicializar git
   git init
   git add .
   git commit -m "Initial commit"
   git branch -M main
   git remote add origin https://github.com/seu-usuario/esocial-service.git
   git push -u origin main
   ```

3. **Conectar no Heroku**:
   - No dashboard do Heroku, conecte este novo repositório
   - Faça o deploy normalmente

### Alternativa: Usar Heroku CLI com Subpasta

Se preferir usar CLI:
```bash
cd esocial-service
heroku git:remote -a seu-app-esocial-service
git subtree push --prefix esocial-service heroku main
```

Mas isso é mais complexo. **Recomendamos criar um repositório separado**.

## Estrutura Final

```
Dashboard Heroku
├── App Node.js (seu-app-nodejs)
│   └── Config Vars
│       └── ESOCIAL_SERVICE_URL = https://seu-app-esocial-service.herokuapp.com
│
└── App eSocial Service (seu-app-esocial-service)
    ├── Buildpack: heroku/php
    ├── Deploy: Repositório GitHub separado (esocial-service/)
    └── URL: https://seu-app-esocial-service.herokuapp.com

GitHub
├── Repositório Principal (seu-projeto)
│   ├── client/
│   ├── server/
│   └── esocial-service/  (código fonte)
│
└── Repositório eSocial Service (esocial-service)
    ├── composer.json  (na raiz!)
    ├── index.php      (na raiz!)
    ├── src/
    └── ... (todo conteúdo de esocial-service/)
```

