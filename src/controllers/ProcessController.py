from .BaseController import BaseController
from .ProjectController import ProjectController
import os
import re
from langchain_community.document_loaders import TextLoader
from langchain_community.document_loaders import PyMuPDFLoader
from models import ProcessingEnum
from typing import List, Dict, Any, Optional
from dataclasses import dataclass
import json

@dataclass
class Document:
    page_content: str
    metadata: dict

class ProcessController(BaseController):

    def __init__(self, project_id: str):
        super().__init__()

        self.project_id = project_id
        self.project_path = ProjectController().get_project_path(project_id=project_id)

    def get_file_extension(self, file_id: str):
        return os.path.splitext(file_id)[-1]

    def get_file_loader(self, file_id: str):

        file_ext = self.get_file_extension(file_id=file_id)
        file_path = os.path.join(
            self.project_path,
            file_id
        )

        if not os.path.exists(file_path):
            return None

        if file_ext == ProcessingEnum.TXT.value:
            return TextLoader(file_path, encoding="utf-8")

        if file_ext == ProcessingEnum.PDF.value:
            return PyMuPDFLoader(file_path)
        
        return None

    def get_file_content(self, file_id: str):

        loader = self.get_file_loader(file_id=file_id)
        if loader:
            return loader.load()

        return None

    def detect_content_type(self, file_content: list, file_id: str) -> str:
        """
        Detect if the content is from a video transcript (Whisper) or PDF based on patterns
        """
        if not file_content:
            return "unknown"
        
        # Check file extension first
        file_ext = self.get_file_extension(file_id).lower()
        if file_ext == '.pdf':
            return "pdf"
        
        # For text files, analyze content patterns
        sample_text = file_content[0].page_content if file_content else ""
        
        # Whisper transcripts are typically continuous text without clear paragraph breaks
        # and often have timestamp patterns if available
        timestamp_patterns = [
            r'\d{1,2}:\d{2}:\d{2}',  # HH:MM:SS
            r'\d{1,2}:\d{2}',        # MM:SS
            r'\[\d+:\d+\]',          # [MM:SS]
            r'\(\d+:\d+\)',          # (MM:SS)
        ]
        
        has_timestamps = any(re.search(pattern, sample_text) for pattern in timestamp_patterns)
        
        # Check for transcript-like characteristics
        avg_sentence_length = len(sample_text.split()) / max(len(sample_text.split('.')), 1)
        has_few_paragraphs = len(sample_text.split('\n\n')) < 5
        
        if has_timestamps or (avg_sentence_length > 15 and has_few_paragraphs):
            return "transcript"
        
        return "text"

    def extract_timestamps_from_text(self, text: str) -> List[Dict[str, Any]]:
        """
        Extract timestamps from text and return segments with time markers
        """
        timestamp_patterns = [
            (r'(\d{1,2}:\d{2}:\d{2})', r'\1'),  # HH:MM:SS
            (r'(\d{1,2}:\d{2})', r'\1'),        # MM:SS
            (r'\[(\d+:\d+)\]', r'\1'),          # [MM:SS]
            (r'\((\d+:\d+)\)', r'\1'),          # (MM:SS)
        ]
        
        segments = []
        
        for pattern, replacement in timestamp_patterns:
            matches = list(re.finditer(pattern, text))
            if matches:
                last_end = 0
                for i, match in enumerate(matches):
                    start_pos = match.start()
                    timestamp = match.group(1)
                    
                    # Get text from last timestamp to current
                    if i > 0:
                        segment_text = text[last_end:start_pos].strip()
                        if segment_text:
                            segments.append({
                                'timestamp': prev_timestamp,
                                'text': segment_text,
                                'start_pos': last_end,
                                'end_pos': start_pos
                            })
                    
                    last_end = match.end()
                    prev_timestamp = timestamp
                
                # Add final segment
                if matches:
                    final_text = text[last_end:].strip()
                    if final_text:
                        segments.append({
                            'timestamp': matches[-1].group(1),
                            'text': final_text,
                            'start_pos': last_end,
                            'end_pos': len(text)
                        })
                break  # Use first pattern that matches
        
        return segments

    def estimate_timestamps(self, chunks: List[str], total_duration: Optional[int] = None) -> List[Dict[str, Any]]:
        """
        Estimate timestamps for chunks when explicit timestamps aren't available
        """
        if not total_duration:
            # Estimate based on average speech rate (150 words per minute)
            total_words = sum(len(chunk.split()) for chunk in chunks)
            total_duration = int((total_words / 150) * 60)  # seconds
        
        segments = []
        current_time = 0
        # Skip image chunks
        for chunk in chunks:
            if chunk.startswith("Image:"):
                # Pass through without estimation
                segments.append({
                    'timestamp': "",
                    'text': chunk,
                    'estimated': False
                })
                continue        

            word_count = len(chunk.split())
            chunk_duration = int((word_count / 150) * 60)  # seconds
            
            minutes = current_time // 60
            seconds = current_time % 60
            timestamp = f"{minutes}:{seconds:02d}"
            
            segments.append({
                'timestamp': timestamp,
                'text': chunk,
                'estimated': True
            })
            
            current_time += chunk_duration
        
        return segments

    def get_optimal_chunk_size(self, content_type: str, text_length: int) -> Dict[str, int]:
        """
        Get optimal chunk size and overlap based on content type and text characteristics
        """
        if content_type == "transcript":
            # Transcripts benefit from larger chunks to maintain conversational context
            # Average sentence in speech: 15-20 words, aim for 3-5 sentences per chunk
            base_chunk_size = 800
            overlap_size = 150
            
            # Adjust based on text length
            if text_length < 2000:  # Short transcript
                base_chunk_size = 400
                overlap_size = 80
            elif text_length > 20000:  # Very long transcript
                base_chunk_size = 1200
                overlap_size = 200
                
        elif content_type == "pdf":
            # PDFs often have structured content, smaller chunks work better
            # Academic papers benefit from paragraph-level chunking
            base_chunk_size = 600
            overlap_size = 120
            
            # Adjust based on text length
            if text_length < 1000:  # Short document
                base_chunk_size = 300
                overlap_size = 60
            elif text_length > 50000:  # Very long document
                base_chunk_size = 900
                overlap_size = 180
                
        else:  # Regular text
            # Default balanced approach
            base_chunk_size = 500
            overlap_size = 100
            
            # Adjust based on text length
            if text_length < 1500:
                base_chunk_size = 300
                overlap_size = 60
            elif text_length > 30000:
                base_chunk_size = 800
                overlap_size = 160
        
        return {
            'chunk_size': base_chunk_size,
            'overlap_size': overlap_size
        }

    def process_file_content(self, file_content: list, file_id: str,
                            chunk_size: int=None, overlap_size: int=None):
        """
        Enhanced file content processing with better chunking and metadata preservation
        """
        content_type = self.detect_content_type(file_content, file_id)
        
        # Get full text length for optimization
        full_text = " ".join([rec.page_content for rec in file_content])
        text_length = len(full_text)
        
        # Use optimal chunk sizes if not specified
        if chunk_size is None or overlap_size is None:
            optimal_sizes = self.get_optimal_chunk_size(content_type, text_length)
            chunk_size = chunk_size or optimal_sizes['chunk_size']
            overlap_size = overlap_size or optimal_sizes['overlap_size']
        
        if content_type == "transcript":
            return self.process_transcript_content(file_content, file_id, chunk_size, overlap_size)
        elif content_type == "pdf":
            return self.process_pdf_content(file_content, file_id, chunk_size, overlap_size)
        else:
            return self.process_text_content(file_content, file_id, chunk_size, overlap_size)

    def process_transcript_content(self, file_content: list, file_id: str, 
                                 chunk_size: int, overlap_size: int) -> List[Document]:
        """
        Process transcript content with timestamp preservation
        """
        full_text = " ".join([rec.page_content for rec in file_content])
        base_metadata = file_content[0].metadata if file_content else {}
        
        # Try to extract existing timestamps
        timestamp_segments = self.extract_timestamps_from_text(full_text)
        
        chunks = []
        
        if timestamp_segments:
            # Process segments with existing timestamps
            for segment in timestamp_segments:
                segment_chunks = self.create_chunks_with_overlap(
                    segment['text'], chunk_size, overlap_size
                )
                
                for i, chunk_text in enumerate(segment_chunks):
                    metadata = {
                        **base_metadata,
                        'content_type': 'transcript',
                        'timestamp': segment['timestamp'],
                        'chunk_index': i,
                        'total_chunks': len(segment_chunks),
                        'source_file': file_id,
                        'has_timestamp': True
                    }
                    
                    # Add contextual information
                    enhanced_content = f"[{segment['timestamp']}] {chunk_text}"
                    
                    chunks.append(Document(
                        page_content=enhanced_content,
                        metadata=metadata
                    ))
        else:
            # No explicit timestamps, create chunks and estimate timestamps
            text_chunks = self.create_chunks_with_overlap(full_text, chunk_size, overlap_size)
            estimated_segments = self.estimate_timestamps(text_chunks)
            
            for i, segment in enumerate(estimated_segments):
                metadata = {
                    **base_metadata,
                    'content_type': 'transcript',
                    'timestamp': segment['timestamp'],
                    'chunk_index': i,
                    'total_chunks': len(estimated_segments),
                    'source_file': file_id,
                    'has_timestamp': False,
                    'timestamp_estimated': True
                }
                
                # Add contextual information
                if segment["timestamp"]:
                    enhanced_content = f"[~{segment['timestamp']}] {segment['text']}"
                else:
                    enhanced_content = segment['text']
                
                chunks.append(Document(
                    page_content=enhanced_content,
                    metadata=metadata
                ))
        
        return chunks

    def process_pdf_content(self, file_content: list, file_id: str, 
                           chunk_size: int, overlap_size: int) -> List[Document]:
        """
        Process PDF content with page number preservation
        """
        chunks = []
        
        for page_doc in file_content:
            page_content = page_doc.page_content
            page_metadata = page_doc.metadata
            page_num = page_metadata.get('page', 0) + 1   # fix pages numbering not starting from 0
            
            # Create chunks for this page
            page_chunks = self.create_chunks_with_overlap(page_content, chunk_size, overlap_size)
            
            for i, chunk_text in enumerate(page_chunks):
                metadata = {
                    **page_metadata,
                    'content_type': 'pdf',
                    'page_number': page_num,
                    'chunk_index': i,
                    'total_chunks_on_page': len(page_chunks),
                    'source_file': file_id
                }
                
                # Add contextual information
                enhanced_content = f"[Page {page_num}] {chunk_text}"
                
                chunks.append(Document(
                    page_content=enhanced_content,
                    metadata=metadata
                ))
        
        return chunks

    def process_text_content(self, file_content: list, file_id: str, 
                           chunk_size: int, overlap_size: int) -> List[Document]:
        """
        Process regular text content
        """
        file_content_texts = [rec.page_content for rec in file_content]
        file_content_metadata = [rec.metadata for rec in file_content]
        
        full_text = " ".join(file_content_texts)
        base_metadata = file_content_metadata[0] if file_content_metadata else {}
        
        # Create chunks with overlap
        text_chunks = self.create_chunks_with_overlap(full_text, chunk_size, overlap_size)
        
        chunks = []
        for i, chunk_text in enumerate(text_chunks):
            metadata = {
                **base_metadata,
                'content_type': 'text',
                'chunk_index': i,
                'total_chunks': len(text_chunks),
                'source_file': file_id
            }
            
            chunks.append(Document(
                page_content=chunk_text,
                metadata=metadata
            ))
        
        return chunks

    def create_chunks_with_overlap(self, text: str, chunk_size: int, overlap_size: int) -> List[str]:
        """
        Create text chunks with specified overlap
        """
        if len(text) <= chunk_size:
            return [text]
        
        chunks = []
        start = 0
        
        while start < len(text):
            end = start + chunk_size
            
            # If this isn't the last chunk, try to break at sentence boundary
            if end < len(text):
                # Look for sentence endings within the last 100 characters
                search_start = max(end - 100, start)
                sentence_endings = ['.', '!', '?', '\n']
                
                best_break = end
                for ending in sentence_endings:
                    last_occurrence = text.rfind(ending, search_start, end)
                    if last_occurrence > search_start:
                        best_break = last_occurrence + 1
                        break
                
                chunk = text[start:best_break].strip()
            else:
                chunk = text[start:].strip()
            
            if chunk:  # Only add non-empty chunks
                chunks.append(chunk)
            
            # Move start position considering overlap
            if end >= len(text):
                break
            
            start = max(start + chunk_size - overlap_size, start + 1)
        
        return chunks

    def process_simpler_splitter(self, texts: List[str], metadatas: List[dict], 
                               chunk_size: int, splitter_tag: str=". "):
        """
        Legacy method - kept for backwards compatibility
        """
        full_text = " ".join(texts)
        
        # For whisper transcripts, try multiple splitting strategies
        if splitter_tag == "\n" and "\n" not in full_text:
            # Fallback to sentence splitting for continuous text
            splitter_tag = ". "
        
        # Split by splitter_tag
        segments = [doc.strip() for doc in full_text.split(splitter_tag) if len(doc.strip()) > 1]
        
        chunks = []
        current_chunk = ""
        
        for segment in segments:
            # Check if adding this segment would exceed chunk_size
            potential_chunk = current_chunk + segment + splitter_tag
            
            if len(potential_chunk) > chunk_size and len(current_chunk) > 0:
                # Save current chunk and start new one
                chunks.append(Document(
                    page_content=current_chunk.strip(),
                    metadata=metadatas[0] if metadatas else {}
                ))
                current_chunk = segment + splitter_tag
            else:
                current_chunk = potential_chunk
        
        # Add final chunk if it has content
        if len(current_chunk.strip()) > 0:
            chunks.append(Document(
                page_content=current_chunk.strip(),
                metadata=metadatas[0] if metadatas else {}
            ))
        
        return chunks