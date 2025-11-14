# Solução: Erro "Application not supported by this buildpack"

## Problema

O Heroku está retornando o erro:
```
ERROR: Application not supported by this buildpack!
The 'heroku/php' buildpack is set on this application, but was unable to detect a PHP codebase.
A PHP app on Heroku requires a 'composer.json' at the root of the directory structure
```

## Causa

O `composer.json` está dentro da pasta `esocial-service/`, mas o Heroku precisa que ele esteja na **raiz** do repositório que está sendo deployado.

## Solução: Criar Repositório Separado (Recomendado)

A melhor solução é criar um **repositório GitHub separado** apenas para o esocial-service, com todos os arquivos na raiz.

### Passo 1: Criar Novo Repositório no GitHub

1. Acesse [GitHub](https://github.com)
2. Clique em **"New repository"**
3. Nome: `esocial-service` (ou outro nome)
4. Público ou privado (sua escolha)
5. **NÃO** marque "Initialize with README"
6. Clique em **"Create repository"**

### Passo 2: Preparar e Fazer Push

No seu computador, execute:

```bash
# Navegar para a pasta esocial-service
cd esocial-service

# Inicializar git (se ainda não tiver)
git init

# Adicionar todos os arquivos
git add .

# Fazer commit
git commit -m "Initial commit - eSocial Service"

# Adicionar remote do GitHub
git remote add origin https://github.com/SEU-USUARIO/esocial-service.git

# Renomear branch para main (se necessário)
git branch -M main

# Fazer push
git push -u origin main
```

**Substitua `SEU-USUARIO` pelo seu usuário do GitHub!**

### Passo 3: Conectar no Heroku

1. No dashboard do Heroku, vá para o app `seu-app-esocial-service`
2. Aba **"Deploy"**
3. Seção **"Deployment method"** → **"GitHub"**
4. Procure pelo repositório `esocial-service` que você acabou de criar
5. Clique em **"Connect"**
6. Selecione o branch `main`
7. Clique em **"Deploy Branch"**

Agora o deploy deve funcionar! ✅

## Verificação

Após o deploy, teste:

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

## Estrutura do Repositório

O repositório `esocial-service` no GitHub deve ter esta estrutura (tudo na raiz):

```
esocial-service/
├── composer.json      ← Na raiz!
├── index.php          ← Na raiz!
├── Procfile
├── .htaccess
├── src/
│   ├── ApiRouter.php
│   └── Controllers/
├── vendor/            (será criado pelo Heroku)
└── ...
```

## Alternativa: Usar Heroku CLI

Se preferir usar CLI ao invés do dashboard:

```bash
cd esocial-service
heroku git:remote -a seu-app-esocial-service
git push heroku main
```

Mas você ainda precisa ter o `composer.json` na raiz do que está fazendo push.

## Por que isso acontece?

O Heroku detecta o tipo de aplicação olhando os arquivos na **raiz** do repositório:
- `composer.json` = Aplicação PHP
- `package.json` = Aplicação Node.js
- `requirements.txt` = Aplicação Python
- etc.

Se esses arquivos estão em subpastas, o Heroku não consegue detectar automaticamente.

## Próximos Passos

Após resolver o deploy:

1. ✅ Configure a variável `ESOCIAL_SERVICE_URL` no app Node.js
2. ✅ Teste o endpoint `/health`
3. ✅ Configure o certificado digital através da API
4. ✅ Teste envio de um evento

