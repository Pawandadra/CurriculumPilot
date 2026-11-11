from pathlib import Path

try:
    from dotenv import load_dotenv

    load_dotenv(Path(__file__).resolve().parent / ".env", override=False)
except ImportError:
    pass

import json
import os

from openai import OpenAI

_DEFAULT_OPENAI_BASE = "http://localhost:11434/v1"
_DEFAULT_OPENAI_KEY = "ollama"
_DEFAULT_OPENAI_MODEL = "qwen3:14b"

client = OpenAI(
    base_url=(
        os.environ.get("OPENAI_BASE_URL", _DEFAULT_OPENAI_BASE).strip()
        or _DEFAULT_OPENAI_BASE
    ),
    api_key=(
        os.environ.get("OPENAI_API_KEY", _DEFAULT_OPENAI_KEY).strip()
        or _DEFAULT_OPENAI_KEY
    ),
)

def smart_chunk_text(text, target_chars=2500):
    paragraphs = text.split('\n\n')
    chunks = []
    current_chunk = ""

    for paragraph in paragraphs:
        if len(current_chunk) + len(paragraph) + 2 < target_chars:
            current_chunk += paragraph + "\n\n"
        else:
            if current_chunk:
                chunks.append(current_chunk.strip())
            current_chunk = paragraph + "\n\n"

    if current_chunk.strip():
        chunks.append(current_chunk.strip())

    return chunks

def process_strict_chapter(chapter_title, full_chapter_text):
    chunk_size = 3000
    chunks = smart_chunk_text(full_chapter_text, target_chars=chunk_size)
    all_topics = []

    print(f"\nProcessing chapter: '{chapter_title}', split into {len(chunks)} chunk(s).")

    system_prompt = (
        "You are a strict data formatter for an academic study platform. "
        "Your task is to convert the provided text into a highly structured JSON format.\n\n"
        "RULES:\n"
        "1. Clean the text of OCR typos and punctuation errors.\n"
        "2. Keep the original story, facts, and tone perfectly intact.\n"
        "3. The content must be strictly study-focused. Do not add external commentary, and absolutely do not mention job or employment applications.\n"
        "4. Generate a MAXIMUM of 4 multiple-choice questions per topic. If the text is short, 2 or 3 is fine.\n"
        "5. The 'answer' key MUST be an integer representing the correct option's index (0, 1, 2, or 3).\n\n"
        "REQUIRED JSON SCHEMA:\n"
        "{\n"
        '  "topics": [\n'
        "    {\n"
        '      "topic_name": "A suitable heading",\n'
        '      "topic_content": "The cleaned, original text for this section",\n'
        '      "questions": [\n'
        "        {\n"
        '          "question": "What happened to the character?",\n'
        '          "options": ["He fell", "He slept", "He ran", "He ate"],\n'
        '          "answer": 0\n'
        "        }\n"
        "      ]\n"
        "    }\n"
        "  ]\n"
        "}"
    )

    for i, chunk in enumerate(chunks):
        print(f"  - Processing chunk {i+1}/{len(chunks)} ...")

        try:
            model = (
                os.environ.get("OPENAI_MODEL", _DEFAULT_OPENAI_MODEL).strip()
                or _DEFAULT_OPENAI_MODEL
            )
            response = client.chat.completions.create(
                model=model,
                messages=[
                    {"role": "system", "content": system_prompt},
                    {"role": "user", "content": f"Text to process:\n{chunk}"}
                ],
                temperature=0.1,
                response_format={"type": "json_object"}
            )
        except Exception as api_exc:
            print(f"  !! API error on chunk {i+1}: {api_exc}")
            continue

        try:
            response_content = response.choices[0].message.content
            chunk_data = json.loads(response_content)
            if isinstance(chunk_data, dict) and "topics" in chunk_data:
                all_topics.extend(chunk_data["topics"])
        except Exception as parse_exc:
            print(f"  !! Failed to parse JSON in chunk {i+1}: {parse_exc}")
            print(f"     Raw output:\n{response.choices[0].message.content}")

    return {
        "chapter_title": chapter_title,
        "topics": all_topics
    }

def generate_strict_course(input_json_path, output_json_path):
    with open(input_json_path, 'r', encoding='utf-8') as file_in:
        chapters = json.load(file_in)

    final_course = []

    for chapter in chapters:
        if not chapter.get("content") or len(chapter["content"]) < 100:
            continue

        processed = process_strict_chapter(chapter["title"], chapter["content"])
        final_course.append(processed)

    with open(output_json_path, 'w', encoding='utf-8') as file_out:
        json.dump(final_course, file_out, indent=4)

    print(f"\nCourse generation complete! Saved to {output_json_path}")

    return final_course
