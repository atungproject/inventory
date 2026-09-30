FROM python:3.12-slim
WORKDIR /app
COPY . .
RUN mkdir -p data uploads
EXPOSE 8000
ENV TRIO_DATA_DIR=/app/data
ENV TRIO_UPLOAD_DIR=/app/uploads
CMD ["python", "server.py", "--host", "0.0.0.0", "--port", "8000"]
