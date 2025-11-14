# Troubleshooting - Serviço eSocial

## Erro 500 ao enviar evento

### Possíveis causas e soluções:

#### 1. Serviço PHP não está rodando
**Sintoma:** Erro "Serviço eSocial não está acessível"

**Solução:**
```bash
cd esocial-service
php -S localhost:8080 -t . index.php
```

#### 2. Composer não instalado
**Sintoma:** Erro "Composer autoload não encontrado"

**Solução:**
```bash
cd esocial-service
composer install
```

#### 3. Biblioteca eSocial não instalada
**Sintoma:** Erro "Biblioteca nfephp-org/sped-esocial não está instalada"

**Solução:**
```bash
cd esocial-service
composer require nfephp-org/sped-esocial:dev-master
```

**Nota:** Certifique-se de que o `composer.json` tem:
```json
{
  "minimum-stability": "dev",
  "prefer-stable": true
}
```

#### 4. Certificado não configurado
**Sintoma:** Erro "Certificado digital não configurado"

**Solução:**
1. Acesse a página de Configuração do eSocial no frontend
2. Faça upload do certificado digital (PFX)
3. Informe a senha do certificado
4. Salve a configuração

#### 5. Dados do empregador não configurados
**Sintoma:** Erro "CNPJ/CPF do empregador não configurado"

**Solução:**
1. Acesse a página de Configuração do eSocial
2. Preencha os dados do empregador (CNPJ/CPF)
3. Salve a configuração

#### 6. Certificado inválido ou expirado
**Sintoma:** Erro "Erro ao carregar certificado"

**Solução:**
- Verifique se o certificado está em formato PFX válido
- Verifique se a senha está correta
- Verifique se o certificado não está expirado
- Certifique-se de que o certificado foi codificado em base64 corretamente

#### 7. Estrutura do evento incorreta
**Sintoma:** Erro ao processar evento

**Solução:**
- Verifique se o tipo do evento está correto
- Verifique se os dados do evento estão no formato correto
- Consulte a documentação da biblioteca nfephp-org/sped-esocial

## Verificar status do serviço

### Teste de Health Check
```bash
curl http://localhost:8080/health
```

Deve retornar:
```json
{
  "status": "ok",
  "service": "esocial-api"
}
```

### Verificar logs do PHP
Se estiver usando o servidor built-in do PHP, os erros aparecerão no terminal.

Para ver logs do Apache/Nginx:
```bash
tail -f /var/log/apache2/error.log
# ou
tail -f /var/log/nginx/error.log
```

## Verificar variável de ambiente

No servidor Node.js, verifique se a variável está configurada:
```bash
echo $ESOCIAL_SERVICE_URL
```

Ou no arquivo `.env`:
```env
ESOCIAL_SERVICE_URL=http://localhost:8080
```

## Testar manualmente

### 1. Testar configuração
```bash
curl -X GET http://localhost:8080/config
```

### 2. Testar envio de evento (exemplo)
```bash
curl -X POST http://localhost:8080/eventos \
  -H "Content-Type: application/json" \
  -d '{
    "evento": {
      "tipo": "S-1000",
      "dados": {}
    }
  }'
```

## Próximos passos

Se o problema persistir:
1. Verifique os logs do servidor Node.js
2. Verifique os logs do serviço PHP
3. Verifique se todas as dependências estão instaladas
4. Consulte a documentação da biblioteca: https://github.com/nfephp-org/sped-esocial

