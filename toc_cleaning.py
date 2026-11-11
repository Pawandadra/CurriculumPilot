import json
import re
from openai import OpenAI

client = OpenAI(
    base_url="http://localhost:11434/v1",
    api_key="ollama"
)

def extract_simple_titles(input_file, output_file):
    with open(input_file, 'r', encoding='utf-8') as fin:
        book_text = fin.read()

    prompt = "Extract only chapter titles into JSON format: [{'title': '...', 'content': ''}]. Fix spelling. No metadata. No page numbers."
    chat_msg = [
        {"role": "system", "content": prompt},
        {"role": "user", "content": book_text}
    ]

    response = client.chat.completions.create(
        model="qwen3:14b",
        messages=chat_msg,
        temperature=0
    )

    output_text = response.choices[0].message.content
    match = re.search(r'\[.*\]', output_text, re.DOTALL)
    cleaned_json = match.group(0) if match else output_text

    try:
        chapter_data = json.loads(cleaned_json)
        with open(output_file, 'w', encoding='utf-8') as fout:
            json.dump(chapter_data, fout, indent=4)
        print(f"Success! {len(chapter_data)} titles saved to {output_file}")
    except Exception as exc:
        print(f"Error parsing JSON: {exc}\nLLM Output was:\n{output_text}")
