#!/usr/bin/env python3
"""Local preview for atlas.html, for machines without PHP (macOS no longer ships it).

Run from this folder:   python3 preview_server.py
Then open:              http://localhost:8765/atlas.html  (list: /index.html)

It answers the same requests as atlas.php and archive.php, reading ai_updates.sqlite
read-only, and serves the other files in this folder. Python standard library
only. Not needed on the web server, which uses atlas.php.
"""
import html, json, os, re, sqlite3, sys
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlparse, parse_qs

HERE = os.path.dirname(os.path.abspath(__file__))
DB = os.path.join(HERE, 'ai_updates.sqlite')
PORT = int(sys.argv[1]) if len(sys.argv) > 1 else 8765

def plain(s):
    t = html.unescape(s or '')
    t = re.sub(r'<[^>]+>', '', t)
    return t.replace('\xa0', ' ')

def summary(desc, mx=260):
    t = plain(desc).strip()
    t = re.split(r'\n\s*\n|\n~\n', t, maxsplit=1)[0]
    t = re.sub(r'\s+', ' ', t).strip()
    if len(t) > mx:
        t = re.sub(r'\s+\S*$', '', t[:mx]) + '…'
    return t

def db():
    return sqlite3.connect(f'file:{DB}?mode=ro', uri=True)

class Handler(SimpleHTTPRequestHandler):
    def __init__(self, *a, **k):
        super().__init__(*a, directory=HERE, **k)

    def send_json(self, obj, code=200):
        body = json.dumps(obj, ensure_ascii=False, separators=(',', ':')).encode('utf-8')
        self.send_response(code)
        self.send_header('Content-Type', 'application/json; charset=utf-8')
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        u = urlparse(self.path)
        name = os.path.basename(u.path)
        if name.endswith('.sqlite') or name.startswith('.'):
            return self.send_error(403)
        if u.path.rstrip('/').endswith('archive.php'):
            # same answers as archive.php, for the list page (index.html)
            q = parse_qs(u.query)
            g = lambda k, d='': (q.get(k) or [d])[0]
            try:
                page = max(1, int(g('page', '1'))); limit = max(1, min(1000, int(g('limit', '50'))))
            except ValueError:
                page, limit = 1, 50
            where, params = [], []
            if g('search'):
                where.append('(title LIKE ? OR description LIKE ?)'); params += ['%' + g('search') + '%'] * 2
            if g('filterTag'):
                where.append('tags LIKE ?'); params.append('%' + g('filterTag') + '%')
            w = (' WHERE ' + ' AND '.join(where)) if where else ''
            with db() as c:
                c.row_factory = sqlite3.Row
                total = c.execute('SELECT COUNT(*) FROM entries' + w, params).fetchone()[0]
                rows = [dict(r) for r in c.execute('SELECT * FROM entries' + w + ' ORDER BY date_unix DESC, id DESC LIMIT ? OFFSET ?', params + [limit, (page - 1) * limit])]
            return self.send_json({'data': rows, 'pagination': {'totalItems': total, 'totalPages': -(-total // limit), 'currentPage': page, 'itemsPerPage': limit}})
        if u.path.rstrip('/').endswith('atlas.php'):
            q = parse_qs(u.query)
            with db() as c:
                if 'id' in q:
                    try:
                        i = int(q['id'][0])
                    except ValueError:
                        return self.send_json({'error': 'Bad id'}, 400)
                    r = c.execute('SELECT id, description FROM entries WHERE id = ?', (i,)).fetchone()
                    if not r:
                        return self.send_json({'error': 'Not found'}, 404)
                    return self.send_json({'id': r[0], 'text': plain(r[1]).strip()})
                if 'q' in q:
                    s = q['q'][0].strip()
                    if not s or len(s) > 120:
                        return self.send_json({'ids': []})
                    like = '%' + s.replace('\\', '\\\\').replace('%', '\\%').replace('_', '\\_') + '%'
                    ids = [r[0] for r in c.execute(
                        "SELECT id FROM entries WHERE title LIKE ? ESCAPE '\\' OR description LIKE ? ESCAPE '\\'", (like, like))]
                    return self.send_json({'ids': ids})
                rows = [[r[0], r[1] or 0, re.sub(r'\s+', ' ', plain(r[2])).strip(), summary(r[3]), r[4] or '', r[5] or '']
                        for r in c.execute('SELECT id, date_unix, title, description, link, tags FROM entries ORDER BY date_unix DESC, id DESC')]
            return self.send_json({'updated': int(os.path.getmtime(DB)), 'entries': rows})
        return super().do_GET()

    def log_message(self, *a):
        pass

if __name__ == '__main__':
    if not os.path.exists(DB):
        sys.exit('ai_updates.sqlite not found next to this script.')
    print(f'Atlas preview: http://localhost:{PORT}/atlas.html  (Ctrl+C to stop)')
    ThreadingHTTPServer(('127.0.0.1', PORT), Handler).serve_forever()
