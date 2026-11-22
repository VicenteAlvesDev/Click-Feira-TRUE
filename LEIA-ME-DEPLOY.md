# ClickFeira Assaré - publicar hoje

## 1. Antes de subir
1. Banco: rode `database/producao.sql` no phpMyAdmin (índices + e-mail único + usuário do banco).
2. Edite `config/config.php` com o host, banco, usuário e senha do SERVIDOR (não use root/root).
3. Suba TODOS os arquivos, inclusive os que começam com ponto (`.htaccess`).
   Não suba a pasta `.git`.
4. Confirme que as pastas `uploads/products`, `uploads/profiles` e `uploads/stalls` aceitam escrita (permissão 755 ou 775).
5. Domínio com HTTPS ativo (o PWA só instala em HTTPS). O `.htaccess` já redireciona.

## 2. Teste rápido (5 minutos)
- Abrir o site pelo celular (Chrome): deve aparecer o convite "Instalar".
- Criar conta de vendedor, cadastrar produto com foto, ver em "Meus produtos".
- Criar conta de cliente, encomendar o produto, cancelar, encomendar de novo.
- Como vendedor: aceitar, depois concluir. Recusar uma encomenda deve devolver o estoque.
- Desligar a internet do celular e abrir o app: deve mostrar a tela "Sem conexão".
- `https://SEU-DOMINIO/.git/config`, `/config/config.php` e `/includes/bootstrap.php` devem dar "Forbidden".

## 3. Ao publicar uma atualização
Altere `VERSAO` em `service-worker.js` (ex.: `clickfeira-v5`) para os celulares receberem os arquivos novos.

## 4. Requisitos do servidor
Apache com mod_rewrite, PHP 8.0+ com mysqli, GD (com WebP) e, de preferência, exif.
Em Nginx o `.htaccess` não funciona: peça ao suporte para bloquear `.git`, `config`, `includes`, `database`
e impedir execução de PHP dentro de `uploads/`.
