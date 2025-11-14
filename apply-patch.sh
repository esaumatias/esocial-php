#!/bin/bash

# Script para aplicar patch na biblioteca nfephp-org/sped-esocial
# Este patch corrige o problema de caminho do definitions.schema

FACTORY_FILE="vendor/nfephp-org/sped-esocial/src/Common/Factory.php"

if [ ! -f "$FACTORY_FILE" ]; then
    echo "Arquivo Factory.php não encontrado. Execute 'composer install' primeiro."
    exit 1
fi

# Verificar se o patch já foi aplicado
if grep -q "realpath.*definitions.schema" "$FACTORY_FILE"; then
    echo "Patch já aplicado."
    exit 0
fi

# Aplicar patch
echo "Aplicando patch no Factory.php..."

# Backup
cp "$FACTORY_FILE" "$FACTORY_FILE.backup"

# Substituir linha do definitions para usar realpath
sed -i 's|$this->definitions = __DIR__ . "/../../jsonSchemes/definitions.schema";|$this->definitions = realpath(__DIR__ . "/../../jsonSchemes/definitions.schema");|g' "$FACTORY_FILE"

# Substituir linha do jsonschema para usar realpath também
sed -i 's|$this->jsonschema = __DIR__ . "/../../jsonSchemes/$this->layoutStr/{$this->evtName}.schema";|$this->jsonschema = realpath(__DIR__ . "/../../jsonSchemes/$this->layoutStr/{$this->evtName}.schema");|g' "$FACTORY_FILE"

# Patch no JsonValidation.php para mesclar definitions
VALIDATION_FILE="vendor/nfephp-org/sped-esocial/src/Common/JsonValidation.php"
if [ -f "$VALIDATION_FILE" ]; then
    # Verificar se o patch já foi aplicado
    if grep -q "Carregar definitions.schema e mesclar" "$VALIDATION_FILE"; then
        echo "Patch JsonValidation já aplicado."
    else
        echo "Aplicando patch no JsonValidation.php..."
        cp "$VALIDATION_FILE" "$VALIDATION_FILE.backup"
        
        # Substituir a lógica de addSchema para mesclar definitions
        # Isso é mais complexo, então vamos usar um arquivo de patch temporário
        cat > /tmp/jsonvalidation_patch.php << 'EOFPATCH'
<?php
$file = 'vendor/nfephp-org/sped-esocial/src/Common/JsonValidation.php';
$content = file_get_contents($file);

// Padrão antigo que pode existir
$old1 = '$jsonSchemaObject = json_decode((string)file_get_contents($jsonschema));
        $schemaStorage = new SchemaStorage();
        $schemaStorage->addSchema("file:{$definitions}", $jsonSchemaObject);';

// Novo padrão com mesclagem de definitions e ID do schema
$new = '$jsonSchemaObject = json_decode((string)file_get_contents($jsonschema));
        
        // Carregar definitions.schema e mesclar com o schema principal
        if (is_file($definitions)) {
            $definitionsContent = json_decode((string)file_get_contents($definitions));
            // Mesclar definitions no schema principal
            if (isset($definitionsContent->definitions)) {
                // Mesclar definitions existentes com as novas (se houver)
                if (!isset($jsonSchemaObject->definitions)) {
                    $jsonSchemaObject->definitions = new \stdClass();
                }
                foreach ($definitionsContent->definitions as $key => $value) {
                    $jsonSchemaObject->definitions->$key = $value;
                }
            }
        }
        
        // Definir ID do schema para permitir resolução de fragmentos
        if (!isset($jsonSchemaObject->id)) {
            $jsonSchemaObject->id = "file://" . realpath($jsonschema);
        }
        
        $schemaStorage = new SchemaStorage();
        // Adicionar o schema principal (agora com definitions mescladas)
        $schemaStorage->addSchema("file:{$jsonschema}", $jsonSchemaObject);';

// Tentar substituir o padrão antigo
if (strpos($content, $old1) !== false) {
    $content = str_replace($old1, $new, $content);
} else {
    // Se não encontrar o padrão antigo, pode ser que já tenha sido parcialmente modificado
    // Vamos substituir apenas a parte que falta
    $pattern = '/\$jsonSchemaObject = json_decode\(\(string\)file_get_contents\(\$jsonschema\)\);/';
    if (preg_match($pattern, $content) && strpos($content, 'Carregar definitions.schema') === false) {
        // Substituir apenas a parte inicial e adicionar a mesclagem
        $replacement = '$jsonSchemaObject = json_decode((string)file_get_contents($jsonschema));
        
        // Carregar definitions.schema e mesclar com o schema principal
        if (is_file($definitions)) {
            $definitionsContent = json_decode((string)file_get_contents($definitions));
            if (isset($definitionsContent->definitions)) {
                if (!isset($jsonSchemaObject->definitions)) {
                    $jsonSchemaObject->definitions = new \stdClass();
                }
                foreach ($definitionsContent->definitions as $key => $value) {
                    $jsonSchemaObject->definitions->$key = $value;
                }
            }
        }
        
        // Definir ID do schema para permitir resolução de fragmentos
        if (!isset($jsonSchemaObject->id)) {
            $jsonSchemaObject->id = "file://" . realpath($jsonschema);
        }';
        $content = preg_replace($pattern, $replacement, $content);
        
        // Garantir que o SchemaStorage adicione o schema corretamente
        if (strpos($content, '$schemaStorage->addSchema("file:{$definitions}"') !== false) {
            $content = str_replace(
                '$schemaStorage->addSchema("file:{$definitions}", $jsonSchemaObject);',
                '$schemaStorage->addSchema("file:{$jsonschema}", $jsonSchemaObject);',
                $content
            );
        }
    }
}

file_put_contents($file, $content);
echo "Patch JsonValidation aplicado!\n";
EOFPATCH
        php /tmp/jsonvalidation_patch.php
        rm /tmp/jsonvalidation_patch.php
    fi
fi

echo "Patch aplicado com sucesso!"

