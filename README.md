# Serviço eSocial - Integração PHP

Este serviço atua como ponte entre o sistema Node.js e a biblioteca PHP do eSocial (nfephp-org/sped-esocial).

## Instalação

1. Instalar dependências PHP:
```bash
cd esocial-service
composer install
```

2. Configurar variáveis de ambiente:
```bash
cp .env.example .env
```

3. Configurar certificado digital e dados do empregador através da API ou editando `config.json` diretamente.

## Uso

### Iniciar servidor PHP

```bash
php -S localhost:8080 -t . index.php
```

Ou usando Apache/Nginx com configuração adequada.

## Endpoints da API

### GET /health
Verifica se o serviço está funcionando.

### GET /config
Retorna a configuração atual do eSocial.

### POST /config
Salva nova configuração.

**Body:**
```json
{
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
}
```

### POST /eventos
Envia um evento para o eSocial.

**Body:**
```json
{
  "evento": {
    "tipo": "S-1000",
    "dados": {
      // Dados específicos do evento
    }
  }
}
```

### GET /eventos?protocolo=XXX
Consulta o status de um evento pelo protocolo.

### POST /lotes
Envia um lote de eventos.

**Body:**
```json
{
  "eventos": [
    {
      "tipo": "S-1000",
      "dados": {}
    },
    {
      "tipo": "S-1005",
      "dados": {}
    }
  ]
}
```

### GET /lotes?protocolo=XXX
Consulta o status de um lote pelo protocolo.

### POST /validar
Valida a estrutura de um evento sem enviar.

**Body:**
```json
{
  "evento": {
    "tipo": "S-1000",
    "dados": {}
  }
}
```

## Notas

- O certificado digital deve ser fornecido em formato PFX codificado em base64.
- O ambiente padrão é homologação (tpAmb=2). Para produção, altere para tpAmb=1.
- Consulte a documentação da biblioteca nfephp-org/sped-esocial para detalhes sobre a estrutura de cada tipo de evento.

