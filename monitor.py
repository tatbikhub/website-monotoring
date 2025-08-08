import http.client
import time

host = "get-lookover-auto-sync-api.rf.gd"
path = "/script.php"

while True:
    try:
        conn = http.client.HTTPSConnection(host)
        conn.request("GET", path)
        response = conn.getresponse()
        print(f"Status: {response.status} {response.reason}")
        data = response.read()
        # Uncomment to print response content:
        # print(data.decode())
        conn.close()
    except Exception as e:
        print(f"Request failed: {e}")
    time.sleep(1)
