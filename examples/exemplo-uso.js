/**
 * Exemplos de uso da API eSocial
 * 
 * Este arquivo contém exemplos de como usar a API eSocial
 * a partir do frontend ou de scripts Node.js
 */

// ============================================
// EXEMPLO 1: Configurar eSocial
// ============================================
async function configurarEsocial() {
  const response = await fetch('http://localhost:5000/api/esocial/config', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${seuToken}`
    },
    body: JSON.stringify({
      tpAmb: 2, // 1-Produção, 2-Homologação
      verProc: 'SISTEMA-RH-1.0',
      eventoVersion: '2.5.0',
      serviceVersion: '1.5.0',
      empregador: {
        tpInsc: 1, // 1-CNPJ, 2-CPF
        nrInsc: '12345678000190' // CNPJ sem formatação
      },
      certificate: {
        pfx: 'BASE64_ENCODED_CERTIFICATE_HERE',
        password: 'senha_do_certificado'
      }
    })
  });

  const data = await response.json();
  console.log('Configuração salva:', data);
}

// ============================================
// EXEMPLO 2: Enviar Evento S-1000
// (Informações do Empregador)
// ============================================
async function enviarEventoS1000() {
  const evento = {
    tipo: 'S-1000',
    dados: {
      // Dados do evento S-1000
      // Consulte a documentação da biblioteca para estrutura completa
      ideEmpregador: {
        tpInsc: 1,
        nrInsc: '12345678000190'
      },
      infoEmpregador: {
        // Informações do empregador
      }
    }
  };

  const response = await fetch('http://localhost:5000/api/esocial/eventos', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${seuToken}`
    },
    body: JSON.stringify({ evento })
  });

  const data = await response.json();
  console.log('Evento enviado:', data);
  
  if (data.success && data.data.protocolo) {
    console.log('Protocolo:', data.data.protocolo);
  }
}

// ============================================
// EXEMPLO 3: Consultar Status de Evento
// ============================================
async function consultarEvento(protocolo) {
  const response = await fetch(
    `http://localhost:5000/api/esocial/eventos?protocolo=${protocolo}`,
    {
      method: 'GET',
      headers: {
        'Authorization': `Bearer ${seuToken}`
      }
    }
  );

  const data = await response.json();
  console.log('Status do evento:', data);
}

// ============================================
// EXEMPLO 4: Enviar Lote de Eventos
// ============================================
async function enviarLoteEventos() {
  const eventos = [
    {
      tipo: 'S-1000',
      dados: {
        // Dados do S-1000
      }
    },
    {
      tipo: 'S-1005',
      dados: {
        // Dados do S-1005
      }
    }
  ];

  const response = await fetch('http://localhost:5000/api/esocial/lotes', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${seuToken}`
    },
    body: JSON.stringify({ eventos })
  });

  const data = await response.json();
  console.log('Lote enviado:', data);
}

// ============================================
// EXEMPLO 5: Validar Evento (sem enviar)
// ============================================
async function validarEvento() {
  const evento = {
    tipo: 'S-1000',
    dados: {
      // Dados do evento
    }
  };

  const response = await fetch('http://localhost:5000/api/esocial/validar', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${seuToken}`
    },
    body: JSON.stringify({ evento })
  });

  const data = await response.json();
  
  if (data.success) {
    console.log('✅ Evento válido');
  } else {
    console.log('❌ Erros encontrados:', data.error);
  }
}

// ============================================
// EXEMPLO 6: Usando axios (Node.js/Frontend)
// ============================================
import axios from 'axios';

const api = axios.create({
  baseURL: 'http://localhost:5000/api',
  headers: {
    'Content-Type': 'application/json'
  }
});

// Adicionar token de autenticação
api.interceptors.request.use((config) => {
  const token = localStorage.getItem('hr_system_token');
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

// Função para enviar evento
export const enviarEventoEsocial = async (evento) => {
  try {
    const response = await api.post('/esocial/eventos', { evento });
    return response.data;
  } catch (error) {
    throw new Error(error.response?.data?.error || error.message);
  }
};

// Função para consultar evento
export const consultarEventoEsocial = async (protocolo) => {
  try {
    const response = await api.get('/esocial/eventos', {
      params: { protocolo }
    });
    return response.data;
  } catch (error) {
    throw new Error(error.response?.data?.error || error.message);
  }
};

