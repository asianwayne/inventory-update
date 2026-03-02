import sys

try:
    with open('includes/lang.php', 'rb') as f:
        content = f.read()
    
    # Try different encodings
    for encoding in ['utf-8', 'gbk', 'gb2312', 'utf-16', 'latin-1']:
        try:
            # use errors='replace' to see partial results
            decoded = content.decode(encoding, errors='replace')
            print(f"--- Decoded with {encoding} (first 800 chars) ---")
            print(decoded[:800])
            print("\n" + "="*30 + "\n")
        except Exception as e:
            print(f"--- Failed to decode with {encoding}: {e} ---")

except Exception as e:
    print(f"Error: {e}")
